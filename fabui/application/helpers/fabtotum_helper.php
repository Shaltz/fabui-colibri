<?php
/**
 * 
 * @author Krios Mane
 * @version 0.1
 * @license https://opensource.org/licenses/GPL-3.0
 * 
 */
 
if ( !function_exists('createDefaultSettings'))
{
	/**
	 * 
	 * Create ./settings/default_settings.json with default data
	 * 
	 * 
	 */
	function createDefaultSettings()
	{
		$CI =& get_instance();
		$CI->load->helper('file');
		$CI->config->load('fabtotum');
		
		$dafault_settings = array(
			'color'         	 => array('r'=>255, 'g'=>255, 'b'=>255),
			'safety'        	 => array('door'=>0, 'collision_warning'=>1),
			'switch'        	 => 0,
			'feeder'        	 => array('disengage-offset'=> 2, 'show' => true),
			'milling'       	 => array('layer_offset' => 12),
			'e'             	 => 3048.1593,
			'a'             	 => 177.777778,
			'customized_actions' => array('bothy' => 'none', 'bothz' => 'none'),
			'api'                => array('keys' => array()),
			'zprobe'        	 => array('enable'=>0, 'zmax'=>206),
			'settings_type' 	 => 'default',
			'hardware'     	 	 => array('head' => $CI->config->item('heads').'/hybrid_head.json'),
			'print'         	 => array('pre_heating' => array('nozzle' => 150, 'bed'=>50)),
			'invert_x_endstop_logic' => false
		);
		write_file($CI->config->item('default_settings'), json_encode($dafault_settings));
	}	
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('loadSettings'))
{
	/**
	 * 
	 * 
	 *  Load settings configuration
	 *  @return settings configuration
	 * 
	 */
	function loadSettings($type = 'default')
	{
		$CI =& get_instance();
		$CI->load->helper('file');
		$CI->config->load('fabtotum');
		$settings = json_decode(file_get_contents($CI->config->item($type.'_settings')), true);
		return $settings;
	}
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('saveSettings'))
{
	/**
	 * 
	 * @param $data => data to save
	 * @param $type => wich settings to save
	 * 
	 * 
	 */
	function saveSettings($data, $type = 'default')
	{
		$CI =& get_instance();
		$CI->load->helper('file');
		$CI->config->load('fabtotum');
		
		return write_file($CI->config->item($type.'_settings'), json_encode($data));
	}
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('loadHead'))
{
	/**
	 * Load installed head
	 */	
	function loadHead($type = 'default')
	{
		$settings = loadSettings();
		return json_decode(file_get_contents($settings['hardware']['head']), true);
	}
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('doCommandLine'))
{
	/**
	 * @param $script name
	 * @param args
	 * doCL => do Command Line
	 * exec script from command line
	 */
	function doCommandLine($bin, $scriptPath, $args = '')
	{
		$CI =& get_instance();
		$CI->config->load('fabtotum');
		$CI->load->helper('utility_helper');
		
		$command = $bin.' '.$scriptPath.' ';
		
		if(is_array($args) || $args != ''){
			if(is_assoc($args)){
				foreach($args as $key => $value){
					// if key exists and is not an array's index	
					if(array_key_exists($key, $args) && $key != '' && !is_int($key)){
						$command .= $key.' ';
					}
					if($value != '') $command .= ' '.$value.' ';
				}
			}else{
				foreach($args as $arg){
					$command .= $arg.' ';
				}
			}
		}
		log_message('debug', $command);
		return shell_exec($command);
	}
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('doMacro'))
{
	/**
	 * @param $macroName
	 * @param $traceFile
	 * @param $responseFile
	 * @param $extrArgs
	 * Exec macro operation
	 * 
	 */
	function doMacro($macroName, $traceFile = '', $responseFile = '', $extrArgs = '')
	{
		if($macroName == '') return;
		//load CI instancem, helpers, config
		$CI =& get_instance();
		$CI->load->helper('file');
		$CI->config->load('fabtotum');
		
		$extPath = $CI->config->item('ext_path');
		if($traceFile == '' or $traceFile == null)        $traceFile    = $CI->config->item('trace');
		if($responseFile == '' or $responseFile == null ) $responseFile = $CI->config->item('macro_response');
		
		doCommandLine('python', $extPath.'py/gmacro.py', is_array($extrArgs) ? array_merge(array($macroName, $traceFile, $responseFile), $extrArgs) : array($macroName, $traceFile, $responseFile, $extrArgs));
		//if response is false means that macro failed
		return str_replace(PHP_EOL, '', trim(file_get_contents($responseFile))) == 'true' ? true : false;
	}
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('readInitialTemperatures')) 
{
	/**
	 * @param $file
	 * @param $numLines
	 * get the initial temperatures of an additive file
	 */
	function readInitialTemperatures($file, $numLines = 500){
		
		$re = "\"M(\d+)\sS([+|-]*[0-9]*.[0-9]*)\""; //regular expression to catch temperatures
		$extruderGCodes = array(109);
		$bedGCodes      = array(190);
		
		$extruderTemp = 0;
		$bedTemp      = 0;
		//read first $numLines lines of the file
		$lines = explode(PHP_EOL, doCommandLine('head', '"'.$file.'"', array('-n' => $numLines)));
		foreach($lines as $line){
			preg_match($re, $line, $matches);
			if(count($matches) > 0){
				if(in_array($matches[1], $extruderGCodes)) $extruderTemp = $matches[2];
				if(in_array($matches[1], $bedGCodes))      $bedTemp      = $matches[2];
			}
			
			if($bedTemp > 0 && $extruderTemp > 0) break;
		}
		return array('extruder' => intval($extruderTemp), 'bed' => intval($bedTemp));
	}
	
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('resetController'))
{
	/**
	 * Reset controller board
	 */
	function resetController()
	{
		$CI =& get_instance();
		$CI->config->load('fabtotum');
		$extPath = $CI->config->item('ext_path');
		//use sudo because GPIO needs root permissions
		return doCommandLine('sudo python', $extPath.'py/forceReset.py');
	}
}
///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
if(!function_exists('stopAll'))
{
	/**
	 * Stop all running tasks and scripts
	 */
	function stopAll()
	{
		//kill all scripts	
		shell_exec('sudo killall -KILL python php');
		$CI =& get_instance();
		$CI->config->load('fabtotum');
		$extPath = $CI->config->item('ext_path');
		doCommandLine('php', FCPATH.'index.php Server webSocket &> /var/log/fabui/webSocket.log &');
		doCommandLine('sudo python', $extPath.'py/GPIOMonitor.py &> /var/log/fabui/GPIOMonitor.log &');
		//shell_exec('php '.FCPATH.'index.php Server webSocket &> /var/log/fabui/webSocket.log &');
		//shell_exec('sudo python '.$extPath.'py/GPIOMonitor.py &> /var/log/fabui/GPIOMonitor.log &');
	}
}
?>