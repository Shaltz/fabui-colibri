<?php
/**
 * 
 * @author Krios Mane
 * @version 0.1
 * @license https://opensource.org/licenses/GPL-3.0
 * 
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Cron extends CI_Controller {
	/**
	 * exec all jobs
	 */
	public function all()
	{
		//fix evenutally permissions issues
		$this->load->helper(array('os_helper', 'social_helper'));
		$this->config->load('fabtotum');
		//fix permissions
		fix_folder_permissions($this->config->item('bigtemp_path'), 'www-data');
		//download feeds
		$this->getUpdateJSON();
		//social feeds
		downloadAllFeeds();
		//shop files
		$this->shopFilaments();
		//fix permissions
		fix_folder_permissions($this->config->item('bigtemp_path'), 'www-data');
	}
	
	/**
	 * retrieve fabtotum development's blog feed
	 */
	public function blogFeeds()
	{	
		//load helpers, config
		$this->load->helper('social_helper');
		downloadBlogFeeds();
	}
	/** 
	 * retrieve fabtotum last tweets
	 */
	public function twitterFeeds()
	{
		//load helpers, config
		$this->load->helper('social_helper');
		downloadTwitterFeeds();
	}
	/**
	 * retrieve fabtotum last instagram photos
	 */
	public function instagramFeeds()
	{
		//load helpers, config
		$this->load->helper('social_helper');
		downloadInstagramFeeds();
	}
	/**
	 * retrieve updates json object
	 */
	public function getUpdateJSON()
	{
		//load helpers, config
		$this->config->load('fabtotum');
		//======================
		if(file_exists($this->config->item('task_monitor'))){
			$monitor = json_decode(file_get_contents($this->config->item('task_monitor')), true);
			if(isset($monitor['task']['status']) && $monitor['task']['status'] == 'running') 
				return; //avoid any gcode conflicts and interferances
		}
		//======================
		$this->load->helper('update_helper');
		$this->load->helper('file');
		$updateJSON = json_encode(getUpdateStatus());
		write_file($this->config->item('updates_json_file'), $updateJSON);
	}
	/**
	 * 
	 */
	public function networkInfo()
	{
		//load helpers, config
		$this->load->helper('os_helper');
		writeNetworkInfo();
	}
	/**
	 * 
	 */
	public function shopFilaments()
	{
		//load helpers
		$this->load->helper("shop_helper");
		downloadAllFilamentsFeeds();
	}
	
	/**
	 * 
	 */
	public function sync_remote_projects()
	{
	    $this->load->database();
	    $fabid = '***';
	    $this->load->helpers(array('myfabtotum_helper', 'deshape_helper'));
	    $acces_token = fab_authenticate($fabid, '***');
	    
	    sync_projects($fabid, $acces_token);
	}

}
?>