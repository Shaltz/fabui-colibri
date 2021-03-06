#!/bin/env python
# -*- coding: utf-8; -*-
#
# (c) 2016 FABtotum, http://www.fabtotum.com
#
# This file is part of FABUI.
#
# FABUI is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# FABUI is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with FABUI.  If not, see <http://www.gnu.org/licenses/>.

# Import standard python module
import argparse
import time
from datetime import datetime
import gettext
import os, sys
import json
import errno
from fractions import Fraction
from threading import Event, Thread
try:
    import queue
except ImportError:
    import Queue as queue
    
# Import external modules
from picamera import PiCamera
import numpy as np

# Import internal modules
from fabtotum.utils.translation import _, setLanguage
from fabtotum.fabui.config  import ConfigService
from fabtotum.fabui.gpusher import GCodePusher
import fabtotum.utils.triangulation as tripy
import fabtotum.speedups.triangulation as tricpp
from fabtotum.utils.ascfile import ASCFile
from fabtotum.utils.common  import clear_big_temp

################################################################################

class RotaryScan(GCodePusher):
    """
    Rotary scan application.
    """
    
    XY_FEEDRATE     = 5000
    Z_FEEDRATE      = 1500
    E_FEEDRATE      = 800
    QUEUE_SIZE      = 16
    
    def __init__(self, log_trace, monitor_file, scan_dir, standalone = False, 
                finalize = True, width = 2592, height = 1944, rotation = 0, 
                iso = 800, power = 230, shutter_speed = 35000,
                lang = 'en_US.UTF-8', send_email=False):
        super(RotaryScan, self).__init__(log_trace, monitor_file, use_stdout=False, lang=lang, send_email=send_email)
        
        self.standalone = standalone
        self.finalize   = finalize
        
        self.camera = PiCamera()
        self.camera.resolution = (width, height)
        self.resolution_label = "{0}x{1}".format(width, height)
        self.camera.iso = iso
        self.camera.awb_mode = 'off'
        self.camera.awb_gains = ( Fraction(1.5), Fraction(1.2) )
        self.camera.rotation = rotation
        self.camera.shutter_speed = shutter_speed # shutter_speed in microseconds
        
        self.progress = 0.0
        self.laser_power = power
        self.scan_dir = scan_dir
        
        self.scan_stats = {
            'type'          : 'rotary',
            'projection'    : 'rotary',
            'scan_total'    : 0,
            'scan_current'  : 0,
            'postprocessing_percent'   : 0.0,
            'width'         : width,
            'height'        : height,
            'iso'           : iso,
            'point_count'   : 0,
            'cloud_size'    : 0.0,
            'file_id'       : 0,
            'object_id'     : 0
        }
        
        self.add_monitor_group('scan', self.scan_stats)
        self.ev_resume = Event()
        self.imq = queue.Queue(self.QUEUE_SIZE)
            
    def get_progress(self):
        """ Custom progress implementation """
        return self.progress
    
    def take_a_picture(self, number = 0, suffix = ''):
        """ Camera control wrapper """
        scanfile = os.path.join(self.scan_dir, "{0}{1}.jpg".format(number, suffix) )
        self.camera.capture(scanfile, quality=100)
    
    def __post_processing(self, camera_path, camera_version,
                          start, end, head_x, head_y, bed_z, slices, 
                          cloud_file, task_id, object_id, object_name, file_name):
        """
        """
        threshold = 0
        idx = 0
        point_count = 0
        
        camera_file = os.path.join(camera_path, camera_version + '_intrinsic.json')
        json_f = open(camera_file)
        intrinsic = json.load(json_f)[self.resolution_label]
        
        camera_file = os.path.join(camera_path, camera_version + '_extrinsic.json')
        json_f = open(camera_file)
        extrinsic = json.load(json_f)[self.resolution_label]

        cam_m       = np.matrix( intrinsic['matrix'], dtype=float )
        dist_coefs  = np.matrix( intrinsic['dist_coefs'], dtype=float )
        width       = int(intrinsic['width'])
        height      = int(intrinsic['height'])

        #~ json_f = open('extrinsic.json')
        #~ extrinsic = json.load(json_f)

        offset      = extrinsic['offset']
        M           = np.matrix( extrinsic['M33'] )
        R           = np.matrix( extrinsic['R33'] )
        t           = np.matrix( extrinsic['t'] )
        r           = np.matrix( extrinsic['r'] )
        
        z_offset    = 2*offset[2] - bed_z
        
        asc = ASCFile(cloud_file)
        
        self.trace( _("Post-processing started") )
        
        while True:
            img_idx = self.imq.get()
            
            img_fn   = os.path.join(self.scan_dir, "{0}.jpg".format(img_idx) )
            img_l_fn = os.path.join(self.scan_dir, "{0}_l.jpg".format(img_idx) )
            
            print "post_processing: ", img_idx
            
            if img_idx == None:
                break
                
            # do processing
            #~ line_pos, threshold, w, h = process_slice(img_fn, img_l_fn, threshold)
            xy_line, w, h = tripy.process_slice2(img_fn, img_l_fn, cam_m, dist_coefs, width, height)
            
            pos = float(idx*(end-start))/ float(slices)
            print "{0} / {1}".format(idx,pos)
            #print json.dumps(line_pos)

            #print len(line_pos)

            T = tripy.roty_matrix(pos)
            offset = np.matrix([head_x, head_y, z_offset])
            
            xyz_points = tricpp.laser_line_to_xyz(xy_line, M, R, t, head_x, -100.0, offset, T)

            point_count += asc.write_points(xyz_points)
            
            idx += 1
            
            with self.monitor_lock:
                self.scan_stats['postprocessing_percent'] = float(idx)*100.0 / float(slices)
                self.scan_stats['point_count'] = point_count
                self.scan_stats['cloud_size']  = asc.get_size()
                self.update_monitor_file()
            
            # remove images
            os.remove(img_fn)
            os.remove(img_l_fn)
            
            if self.is_paused():
                self.trace("Paused")
                self.ev_resume.wait()
                self.ev_resume.clear()
                self.trace("Resuming")
            
            if self.is_aborted():
                break
        
        self.trace( _("Post-processin completed") )
        asc.close()
        self.store_object(task_id, object_id, object_name, cloud_file, file_name)
        
    def store_object(self, task_id, object_id, object_name, cloud_file, file_name):
        """
        Store object and file to database. If `object_id` is not zero the new file
        is added to that object. Otherwise a new object is created with name `object_name`.
        If `object_name` is empty an object name is automatically generated. Same goes for
        `file_name`.
        
        :param task_id:     Task ID used to read User ID from the task
        :param object_id:   Object ID used to add file to an object
        :param object_name: Object name used to name the new object
        :param cloud_file:  Full file path and filename to the cloud file to be stored
        :param file_name:   User file name for the cloud file
        :type task_id: int
        :type object_id: int
        :type object_name: string
        :type cloud_file: string
        :type file_name: string
        """
        obj = self.get_object(object_id)
        task = self.get_task(task_id)
        
        if not task:
            return
        
        ts = time.time()
        dt = datetime.fromtimestamp(ts)
        datestr = dt.strftime('%Y-%m-%d %H:%M:%S')
        datestr_fs_friendly = 'cloud_'+dt.strftime('%Y%m%d_%H%M%S')
        
        if not object_name:
            object_name = "Scan object ({0})".format(datestr)
        
        client_name = file_name
        
        if not file_name:
            client_name = datestr_fs_friendly
        
        if not obj:
            # File should not be part of an existing object so create a new one
            user_id = 0
            if task:
                user_id = task['user']
            
            obj = self.add_object(object_name, "", user_id)
        
        f = obj.add_file(cloud_file, client_name=client_name)
        
        if task:
            os.remove(cloud_file)
        
        self.scan_stats['file_id']   = f['id']
        self.scan_stats['object_id'] = obj['id']
        # Update task content
        if task:
            task['id_object'] = obj['id']
            task['id_file'] = f['id']
            task.write()
    
    def state_change_callback(self, state):
        if state == 'resumed' or state == 'aborted':
            self.ev_resume.set()
    
    def run(self, task_id, object_id, object_name, file_name, camera_path, camera_version, start_a, end_a, y_offset, slices, cloud_file):
        """
        Run the rotary scan.
        """
        
         # clear bigtemp folder 
        clear_big_temp()
        
        self.trace( _("Initializing scan") )
        
        self.prepare_task(task_id, task_type='scan', task_controller='scan')
        self.set_task_status(GCodePusher.TASK_RUNNING)
        
        head_y   = y_offset
        head_x   = 96.0
        bed_z    = 135.0 + 45.0 # Platform position + 4axis offset
        
        self.post_processing_thread = Thread(
            target = self.__post_processing,
            args=( [camera_path, camera_version, start_a, end_a, head_x, head_y, bed_z, slices, cloud_file, 
                    task_id, object_id, object_name, file_name] )
            )
        self.post_processing_thread.start()
        
        if self.standalone:
            self.exec_macro("check_pre_scan")
            self.exec_macro("start_rotary_scan")
            
        
        self.trace( _("Scan started") )
        
        LASER_ON  = 'M700 S{0}'.format(self.laser_power)
        LASER_OFF = 'M700 S0'
        
        position = start_a
        
        if start_a != 0:
            # If an offset is set .
            self.send('G0 E{0} F{1}'.format(start_a, self.E_FEEDRATE) )
            
        if(y_offset!=0):
            #if an offset for Z (Y in the rotated reference space) is set, moves to it.
            self.send('G0 Y{0} F{1}'.format(y_offset, self.XY_FEEDRATE))  #go to y offset
        
        #~ dx = abs((float(end_x)-float(start_x))/float(slices))  #mm to move each slice
        deg = abs((float(end_a)-float(start_a))/float(slices))  #degrees to move each slice
        
        self.scan_stats['scan_total'] = slices
        
        for i in xrange(0, slices):
            #move the laser!
            print str(i) + "/" + str(slices) +" (" + str(deg*i) + "/" + str(deg*slices) +")"
            
            self.send('G0 E{0} F{1}'.format(position, self.E_FEEDRATE))
            self.send('M400')

            self.send(LASER_ON)
            self.take_a_picture(i, '_l')
            
            self.send(LASER_OFF)
            self.take_a_picture(i)
            
            self.imq.put(i)
            
            position += deg
            
            with self.monitor_lock:
                self.scan_stats['scan_current'] = i+1
                self.progress = float(i+1)*100.0 / float(slices)
                self.update_monitor_file()
                
            if self.is_aborted():
                break
        
        self.imq.put(None)
        
        self.post_processing_thread.join()
                
        if self.standalone or self.finalize:
            if self.is_aborted():
                self.set_task_status(GCodePusher.TASK_ABORTING)
            else:
                self.set_task_status(GCodePusher.TASK_COMPLETING)
            
            self.exec_macro("end_scan")
            
            if self.is_aborted():
                self.trace( _("Scan aborted.") )
                self.set_task_status(GCodePusher.TASK_ABORTED)
            else:
                self.trace( _("Scan completed.") )
                self.set_task_status(GCodePusher.TASK_COMPLETED)
        
        self.stop()

def cleandirs(path):
    try:
        filelist = [ f for f in os.listdir(path)]
        for f in filelist:
            os.remove(path + '/' +f)
    except Exception as e:
        print e

def makedirs(path):
    """ python implementation of `mkdir -p` """
    try:
        os.makedirs(path)
    except OSError as exc:  # Python >2.5
        if exc.errno == errno.EEXIST and os.path.isdir(path):
            pass
        else:
            raise

def main():
    config = ConfigService()

    # SETTING EXPECTED ARGUMENTS
    destination = config.get('general', 'bigtemp_path')
    
    parser = argparse.ArgumentParser(formatter_class=argparse.ArgumentDefaultsHelpFormatter)
    
    parser.add_argument("-T", "--task-id",     help="Task ID.",              default=0)
    parser.add_argument("-U", "--user-id",     help="User ID. (future use)", default=0)
    parser.add_argument("-O", "--object-id",   help="Object ID.",            default=0)
    parser.add_argument("-N", "--object-name", help="Object name.",          default='')
    parser.add_argument("-F", "--file-name",   help="File name.",            default='')

    parser.add_argument("-d", "--dest",     help="Destination folder.",     default=destination )
    parser.add_argument("-s", "--slices",   help="Number of slices.",       default=100)
    parser.add_argument("-i", "--iso",      help="ISO.",                    default=400)
    parser.add_argument("-p", "--power",    help="Scan laser power 0-255.", default=230)
    parser.add_argument("-W", "--width",    help="Image width in pixels.",  default=1296)
    parser.add_argument("-H", "--height",   help="Image height in pixels",  default=972)
    parser.add_argument("-C", "--camera",   help="Camera version",          default='v1')
    parser.add_argument("-b", "--begin",    help="Begin scanning from X.",  default=0)
    parser.add_argument("-e", "--end",      help="End scanning at X.",      default=360)
    parser.add_argument("-z", "--z-offset", help="Z offset.",               default=0)
    parser.add_argument("-y", "--y-offset", help="Y offset.",               default=175.0)
    parser.add_argument("-a", "--a-offset", help="A offset/rotation.",      default=0)
    parser.add_argument("-o", "--output",   help="Output point cloud file.",default=os.path.join(destination, 'cloud.asc'))
    parser.add_argument("--lang",           help="Output language", 		default='en_US.UTF-8' )
    parser.add_argument("--email",             help="Send an email on task finish", action='store_true', default=False)
    parser.add_argument("--shutdown",          help="Shutdown on task finish", action='store_true', default=False )

    # GET ARGUMENTS
    args = parser.parse_args()

    slices          = int(args.slices)
    destination     = args.dest
    iso             = int(args.iso)
    power           = int(args.power)
    start_a         = float(args.begin)
    end_a           = float(args.end)
    z_offset        = float(args.z_offset)
    y_offset        = float(args.y_offset)
    a_offset        = float(args.a_offset)
    width           = int(args.width)
    height          = int(args.height)
    
    task_id         = int(args.task_id)
    user_id         = int(args.user_id)
    object_id       = int(args.object_id)
    object_name     = args.object_name
    file_name       = args.file_name
    camera_version  = args.camera
    
    if task_id == 0:
        standalone  = True
    else:
        standalone  = False

    cloud_file      = args.output
    lang            = args.lang
    send_email      = bool(args.email)
    monitor_file    = config.get('general', 'task_monitor')
    log_trace       = config.get('general', 'trace')

    scan_dir        = os.path.join(destination, "images")

    if not os.path.exists(scan_dir):
        makedirs(scan_dir)
        
    ##### delete files
    cleandirs(scan_dir)

    camera_path = os.path.join( config.get('hardware', 'cameras') )

    ############################################################################

    print 'ROTARY SCAN MODULE STARTING' 
    print 'scanning from '+str(start_a)+" to "+str(end_a); 
    print 'Num of scans : ', slices
    print 'ISO  setting : ', iso
    print 'Resolution   : ', width ,'*', height, ' px'
    print 'Laser PWM.  : ', power
    print 'z offset     : ', z_offset

    #ESTIMATED SCAN TIME ESTIMATION
    estimated = (slices*1.99)/60
    if estimated<1 :
        estimated *= 60
        unit= "Seconds"
    else:
        unit= "Minutes"

    print 'Estimated Scan time =', str(estimated) + " " + str(unit) + "  [Pessimistic]"

    app = RotaryScan(log_trace, 
                    monitor_file,
                    scan_dir,
                    standalone=standalone,
                    width=width,
                    height=height,
                    iso=iso,
                    power=power,
                    lang=lang,
                    send_email=send_email)

    app_thread = Thread( 
            target = app.run, 
            args=( [task_id, object_id, object_name, file_name, camera_path, camera_version, 
                    start_a, end_a, y_offset, slices, cloud_file] ) 
            )
    app_thread.start()

    app.loop()          # app.loop() must be started to allow callbacks
    app_thread.join()

if __name__ == "__main__":
    main()
