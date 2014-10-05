<?php

// since other plugins might use this class
if(!class_exists('EDD_SL_Plugin_Updater')){
	require_once('EDD_SL_Plugin_Updater.php');
}

class WSAL_LicenseManager {
	/**
	 * @var WpSecurityAuditLog
	 */
	protected $plugin;
	
	protected $plugins = array();
	
	public function __construct(WpSecurityAuditLog $plugin){
		$this->plugin = $plugin;
	}
	
	protected function GetStoreUrl(){
		return 'http://wpwhitesecurity.com/';
	}
	
	public function CountPlugins(){
		return count($this->plugins);
	}
	
	public function Plugins(){
		return $this->plugins;
	}
	
	protected function GetPluginData($pluginFile, $license){
		//$pluginData = get_plugin_data($pluginFile);
		
		$pluginData = get_file_data($pluginFile, array(
				'Name' => 'Plugin Name',
				'PluginURI' => 'Plugin URI',
				'Version' => 'Version',
				'Description' => 'Description',
				'Author' => 'Author',
				'TextDomain' => 'Text Domain',
				'DomainPath' => 'Domain Path',
			), 'plugin' );
		
		return array(
			'PluginData' => $pluginData,
			'EddUpdater' => new EDD_SL_Plugin_Updater(
				$this->GetStoreUrl(),
				$pluginFile,
				array( 
					'license' 	=> $license,
					'item_name' => $pluginData['Name'],
					'author' 	=> $pluginData['Author'],
					'version' 	=> $pluginData['Version'],
				)
			),
		);
	}
	
	public function AddPremiumPlugin($pluginFile){
		//if(is_admin()){ // TODO enable this for performance reasons?
		$name = sanitize_key($pluginFile);
		$license = $this->plugin->settings->GetLicenseKey($name);
		$this->plugins[$name] = $this->GetPluginData($pluginFile, $license);
		//}
	}
	
	protected function GetBlogIds(){
		global $wpdb;
		$sql = 'SELECT blog_id FROM ' . $wpdb->blogs;
		return $wpdb->get_col($sql);
	}
	
	public function ActivateLicense($name, $license){
		$this->plugin->settings->SetLicenseKey($name, $license);

		$plugins = $this->Plugins();
		$api_params = array(
			'edd_action'=> 'activate_license',
			'license' 	=> urlencode($license),
			'item_name' => urlencode($plugins[$name]['PluginData']['Name']),
			'url'       => urlencode(home_url()),
		);
		
		$blog_ids = $this->plugin->IsMultisite() ? $this->GetBlogIds() : array(1);
		
		foreach($blog_ids as $blog_id){
			
			if($this->plugin->IsMultisite())
				$api_params['url'] = urlencode(get_home_url($blog_id));
			
			$response = wp_remote_get(
				add_query_arg($api_params, $this->GetStoreUrl()),
				array('timeout' => 15, 'sslverify' => false)
			);

			if (is_wp_error($response)) {
				$this->plugin->settings->SetLicenseErrors($name, 'Invalid Licensing Server Response: ' . $response->get_error_message());
				$this->DeactivateLicense($name, $license);
				return false;
			}

			$license_data = json_decode(wp_remote_retrieve_body($response));

			if(is_object($license_data)){
				$this->plugin->settings->SetLicenseStatus($name, $license_data->license);
				if($license_data->license !== 'valid'){
					$error = 'License Not Valid';
					if (isset($license_data->error)) $error .= ': ' . ucfirst(str_replace('_', ' ', $license_data->error));
					$this->plugin->settings->SetLicenseErrors($name, $error);
					$this->DeactivateLicense($name, $license);
					return false;
				}
			}else{
				$this->plugin->settings->SetLicenseErrors($name, 'Unexpected Licensing Server Response');
				$this->DeactivateLicense($name, $license);
				return false;
			}
		}
		
		return true;
	}
	
	public function DeactivateLicense($name, $license = null){
		$this->plugin->settings->SetLicenseStatus($name, '');
		
		// deactivate it on the server (if license was given)
		if(!is_null($license)){
			$plugins = $this->Plugins();
			$api_params = array(
				'edd_action'=> 'deactivate_license',
				'license' 	=> urlencode($license),
				'item_name' => urlencode($plugins[$name]['PluginData']['Name']),
				'url'       => urlencode(home_url()),
			);

			$blog_ids = $this->plugin->IsMultisite() ? $this->GetBlogIds() : array(1);

			foreach($blog_ids as $blog_id){

				if($this->plugin->IsMultisite())
					$api_params['url'] = urlencode(get_home_url($blog_id));

				$response = wp_remote_get(
					add_query_arg($api_params, $this->GetStoreUrl()),
					array('timeout' => 15, 'sslverify' => false)
				);

				if (is_wp_error($response)) return false;

				wp_remote_retrieve_body($response);
			}
		}
	}
}
