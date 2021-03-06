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

__authors__ = "Daniel Kesler"
__license__ = "GPL - https://opensource.org/licenses/GPL-3.0"
__version__ = "1.0"

# Import standard python module
import json
import gettext

# Import external modules

# Import internal modules
from fabtotum.fabui.jog import Jog

# Set up message catalog access
tr = gettext.translation('command_parser', 'locale', fallback=True)
_ = tr.ugettext

class CommandParser:
    
    def __init__(self, gcs, jog_response_file, autostart = True, logger = None):
        self.gcs = gcs
        self.jog = False
        if autostart:
            self.jog = Jog(jog_response_file=jog_response_file, gcs=gcs, logger=logger)
            self.jog.start()
            
        if logger:
            self.log = logger
        else:
            self.log = logging.getLogger('CommandParser')
            ch = logging.StreamHandler()
            ch.setLevel(logging.DEBUG)
            formatter = logging.Formatter("%(levelname)s : %(message)s")
            ch.setFormatter(formatter)
            self.log.addHandler(ch)
    
    def start(self):
        if self.jog:
            self.jog.start()
        
    def loop(self):
        if self.jog:
            self.jog.loop()
        
    def stop(self):
        if self.jog:
            self.jog.stop()
    
    def parse_command(self, line):
        args = line.split(':')
        self.log.debug("Command %s", args[0])
        try:
            cmd = args[0]
            if cmd == '!abort':      #~ !abort
                self.gcs.abort()
            
            elif cmd == '!reset':   #~ !reset
                self.gcs.reset()
            
            elif cmd == '!pause':   #~ !pause
                # execute gmacro pause_position
                self.gcs.pause()
                
            elif cmd == '!resume':  #~ !resume
                # execute gmacro resume_from_pause_position
                self.gcs.resume()
                
            elif cmd == '!z_plus':  # !z_plus:<float>
                self.gcs.z_modify(+float(args[1]))
                
            elif cmd == '!z_minus': # !z_minus:<float>
                self.gcs.z_modify(-float(args[1]))
                                
            elif cmd == '!speed':   # !speed:<float>
                self.gcs.send('M220 S{0}'.format(args[1]), block=False)
            
            elif cmd == '!rpm':     # !rpm:<int>
                pass
            
            elif cmd == '!fan':     # !fan:<int>
                self.gcs.send('M106 S{0}\r\n'.format(args[1]), block=False)
                
            elif cmd == '!flow_rate':# !flow_rate:<float>
                self.gcs.send('M221 S{0}\r\n'.format(args[1]), block=False)
                
            elif cmd == '!gcode':   # !gcode:<gcode>
                self.gcs.send(args[1], block=False)
            
            elif cmd == '!jog':     # !jog:<token>,<gcode>
                if len(args) > 1:
                    tags = args[1].split(',')
                    if len(tags) > 1:
                        print "Jog:", tags[0], tags[1]
                        self.jog.send(tags[0], tags[1])
                
            elif cmd == '!jog_clear': # !jog_cclear
                self.jog.clear()
            
            elif cmd == '!notify':    # !notify:<preset>
                self.gcs.push('notify', args[1])
            
            elif cmd == '!gmacro':  # !gmacro:<preset>,<arg1>,<arg2>,...
                pass

            elif cmd == '!auto_shutdown':# !shutdown:<on|off>
                self.gcs.push('config:shutdown', args[1])
                
            elif cmd == '!debug':
                self.gcs.debug_info(args)

        except Exception as e:
            # Just ignore this command
            self.log.error( _("Error parsing command [{0}], {1}").format(line, str(e)) )
    
    def parse_file(self, filename):
        erase_file = False
        
        self.log.debug("Command Parser %s", filename)
        
        with open(filename, 'r+') as file:
            for line in file:
                line = line.strip()
                erase_file = True
                if line:
                    self.parse_command(line)
                
        # Erase the file.
        if erase_file:
            open(filename, 'w').close()
