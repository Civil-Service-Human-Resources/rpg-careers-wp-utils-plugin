<?php
/*
Plugin Name: RPG Utils
Description: Helper functions to support RPG
Version: 1.0.0
Author: Valtech Ltd
Author URI: http://www.valtech.co.uk
Copyright: Valtech Ltd
Text Domain: rpgutils
Domain Path: /lang
*/

if(!defined('ABSPATH')) exit; //EXIT IF ACCESSED DIRECTLY

if(!class_exists('rpgutils')):

class rpgutils{

	var $version = '1.0.0';
	var $settings = array();
	
	function __construct(){
		/* DO NOTHING HERE - ENSURE ONLY INITIALIZED ONCE */
	}

	function initialize(){
		$this->settings = array(
			'name'				=> __('RPG Roles', 'rpgutils'),
			'version'			=> $this->version,
			'all_teams'			=> '',
			'users_teams'		=> '',
		);

		//REGISTER ACTIONS/FILTERS
		add_action('init', array($this, 'add_roles'));
		add_action('init', array($this, 'register_user_taxonomy'));
		add_action('init', array($this, 'remove_hooks'), 999);
		add_filter('login_redirect', array($this, 'login_redirect'), 10, 3);
		
		//TEAMS ACCESS CONTROL
		add_filter('manage_page_posts_columns', array($this, 'manage_columns'));
		add_action('manage_page_posts_custom_column', array($this, 'custom_column'), 10, 2);
		add_action('add_meta_boxes_page', array($this, 'add_meta_boxes'), 10, 2);
		add_action('admin_init', array($this, 'admin_init'));

		add_filter('filter_gtm_instance', array($this, 'filter_gtm_instance'),1);
		//add_action('shutdown', array($this, 'sql_logger'));
	}

	function sql_logger() {
		//PLUS define( 'SAVEQUERIES', true ); IN wp-config.php
		global $wpdb;
		$log_file = fopen(ABSPATH.'/sql_log.txt', 'a');
		fwrite($log_file, "//////////////////////////////////////////\n\n" . date("F j, Y, g:i:s a")."\n");
		foreach($wpdb->queries as $q) {
			fwrite($log_file, $q[0] . " - ($q[1] s)" . "\n\n");
		}
		fclose($log_file);
	}

	function admin_init(){
		add_action('save_post', array($this, 'save_post'),10, 3);
		add_action('admin_notices', array($this, 'handle_admin_error'));
		add_action('load-edit.php', array($this, 'load_edit'));
		

		//GET ALL CURRENT TEAMS AND STORE THEM - SAVES LOOKUPS LATER ON IN CODE
		$teams = array();
		$currentteams = get_terms(array('taxonomy' => 'content_team','hide_empty' => false, 'parent' => 0));
		$count = 0;
		foreach($currentteams as $team) {

			$teams[$count]['term_id'] = $team->term_id;
			$teams[$count]['name'] = $team->name;
			$count++;
		}

		$this->settings['all_teams'] = $teams;

		//STORE TEAMS CURRENT USER HAS ACCESS TO 
		//NB: HOOKS INTO FUNCTION FROM THE 'WP User Groups' PLUGIN
		$this->settings['users_teams'] = (wp_get_terms_for_user(get_current_user_id(), 'content_team')) ? wp_get_terms_for_user(get_current_user_id(), 'content_team') : array();

		//FORCE DATE FORMAT + TIME FORMAT
		update_option('date_format', 'd/m/y');
		update_option('time_format', 'H:i');

		//UNCOMMENT THIS TO REMOVE UNWANTED CAPABILITIES - SET THEM IN THE FUNCTION
		//$this->clean_unwanted_caps();

		add_meta_box('submitdiv', 'Publish', array($this, 'custom_sumbit_meta_box'), 'page', 'side', 'high');

		//***START: KEEP AT BOTTOM OF FUNCTION***
		//NB: KEEP AT BOTTOM OF FUNCTION AS A FEW return STATEMENTS TO BE CAREFUL OF
		global $pagenow;
		if ($pagenow!=='profile.php' && $pagenow!=='user-edit.php') {
			return;
		}
 
	    //IF CURRENT USER CAN CREATE USERS THEN DO NOT AMEND THE SCREEN
		if (current_user_can('create_users')) {
			return;
		}
 
		//CALL OFF TO AMEND THE PROFILE SCREEN
		add_action('admin_footer', array($this,'amend_profile_fields_disable_js'));
		//***END: KEEP AT BOTTOM OF FUNCTION***
	}
	
	function custom_sumbit_meta_box(){
		global $post;
		
		if($post->post_type!=='page'){ return;}

		remove_meta_box('submitdiv', 'page', 'side');

	?>
		<div class="submitbox" id="submitpost">
		<div id="minor-publishing">
		<div style="display:none;">
		<p class="submit"><input type="submit" name="save" id="save" class="button" value="Save"></p></div>

		<div id="minor-publishing-actions">
		<div id="save-action">
		<input type="submit" name="save" id="save-post" value="Save" class="button">
		<span class="spinner"></span>
		</div>
		<div id="preview-action">
		<a class="preview button" href="http://develop.bg4bibpwqg.eu-west-1.elasticbeanstalk.com/?page_id=523&amp;preview=true" target="wp-preview-523" id="post-preview">Preview<span class="screen-reader-text"> (opens in a new window)</span></a>
		<input type="hidden" name="wp-preview" id="wp-preview" value="">
		</div>
		<div class="clear"></div>
		</div>
		
		<div id="misc-publishing-actions">
		<div class="misc-pub-section misc-pub-post-status">
		Status: <span id="post-status-display"><?php echo ucfirst($post->post_status); ?></span>
		</div>
		</div>
		</div>

		<div id="major-publishing-actions">
		<div id="publishing-action" style="width: 100%;">
		<span class="spinner"></span>
		<input name="original_publish" type="hidden" id="original_publish" value="Publish">
		<input type="submit" name="publish" id="publish" class="button button-primary button-large" value="Publish" style="display: none;">
		</div>
		<div class="clear"></div>
		</div>
		</div>
        <?php
	}

	function filter_gtm_instance($code_tag){
		if(GTM_ON){
			$code_tag=str_replace('!!CONTAINER_ID!!', GTM_CONTAINER_ID, $code_tag);
		}else{
			$code_tag = '';
		}
		return $code_tag;
	}

	function load_edit(){
		if ($_GET['post_type'] !== 'page') return;
		add_filter('posts_join', array($this, 'posts_join'), 10, 2);
		add_filter('posts_where', array($this, 'posts_where'),10, 2);
		add_filter('views_edit-page', array($this, 'fix_post_counts')); 
	}

	function login_redirect( $redirect_to, $request, $user ){
		if(isset($_REQUEST['redirect_to'])){
			return $_REQUEST['redirect_to'];
		}
		return admin_url();
	}

	function amend_profile_fields_disable_js(){
	?>
<script type="text/javascript">jQuery(document).ready(function($){var a=jQuery("h3:contains('Relationships')").next('.form-table').find('tr').has('td'); b=a.find('input[type="checkbox"]'),c=a.find('a');if(b){b.each(function(){$(this).attr('disabled','disabled');});}if(c){c.each(function(){$(this).attr('style','display:none');});}});</script>
	<?php
	}


	function bespoke_js_script(){
		global $pagenow;

		if($pagenow==='post-new.php' || $pagenow==='post.php'){
			//NOT PRETTY BUT GETS JOB DONE...
			if (!wp_script_is('jquery','done')) {
				wp_enqueue_script('jquery');
			}
	   ?>
<script type="text/javascript">(function(){jQuery(function(){jQuery('#menu_order').attr('style','display:none;');jQuery('#menu_order').next().attr('style','display:none');jQuery('#menu_order').prev().attr('style','display:none');var f=setInterval(function(){if(jQuery('#step_submit').length){jQuery('#step_submit').attr('style','margin-left:5px;');jQuery('#step_submit').prev('a').attr('style','');clearInterval(f);}},100);});})();</script>
    <?php
		}
	}

	function save_post($post_id, $post, $update){
		if($post->post_type==='page'){

			$error = false;
			$match = false;

			//DELETE ALL META DATA FOR TEAMS
			delete_post_meta($post_id, 'rpg-team');

			//CHECK THAT TEAM HAS BEEN SELECTED
			foreach($_POST as $key => $value)
			{
				if (strstr($key, 'rpg-team')){
					$match = true;
				}
			}

			if($match){
				//GET ANY TEAMS THAT HAVE BEEN SELECTED
				foreach($_POST as $key => $value)
				{
					if (strstr($key, 'rpg-team')){
						//NEED TO CHECK CURRENT USER CAN UPDATE THIS PAGE?

						//STORE IN META DATA
						add_post_meta($post_id, 'rpg-team', $value);
					}
				}
			} else {
				$error = new WP_Error('missing-team', 'No team selected - page status has been changed to DRAFT');
			}

			if ($error) {
				//TRIGGER THE ERROR MESSAGE
				add_filter('redirect_post_location', function($location) use ($error) {
					return add_query_arg(array('rpg-team'=>$error->get_error_code(), 'message'=>10), $location);
				});
			}
		}
	}

	function handle_admin_error(){
		if (array_key_exists('rpg-team', $_GET)) { 
			$errors = get_option('rpg-team');
			$error_msg = '';

			switch($_GET['rpg-team']) {
                case 'missing-team':
                    $error_msg = 'No team selected - page status has been changed to DRAFT';
                    break;
                default:
                    $error_msg = 'An error ocurred when saving the page';
                    break;
            }
			
			//AMEND STATUS OF THE PAGE TO DRAFT - CANNOT BE PUBLISHED WITHOUT A TEAM SELECTED
			global $post;
			wp_update_post(array('ID' => $post->ID, 'post_status' => 'draft'));

			echo '<div class="error"><p>' . $error_msg . '</p></div>';
		}
	}

	function fix_post_counts($views){
		global $current_user, $wp_query;

		if($this->restrict_access()){

			unset($views['mine']);

			$types = array( 
				array('status' =>  NULL),  
				array('status' => 'publish'),  
				array('status' => 'draft'),  
				array('status' => 'pending'),  
				array('status' => 'trash')  
			);  

			//GET THE QUERY VAR post_status
			$status = isset($wp_query->query_vars['post_status']) ? $wp_query->query_vars['post_status'] : NULL;

			foreach($types as $type) {  
				$query = array( 
					'post_type'   => 'page',  
					'post_status' => $type['status']  
				);  
				$result = new WP_Query($query); 

				switch($type['status']){
					case NULL:
						if($result->found_posts > 0){
							$class = ($status == NULL) ? ' class="current"' : '';  
							$views['all'] = sprintf(__('<a href="%s" '.$class.'>All <span class="count">(%d)</span></a>', 'all'), admin_url('edit.php?post_type=page'), $result->found_posts); 
						}
						break;

					case 'publish':
						if($result->found_posts > 0){
							$class = ($status == 'publish') ? ' class="current"' : '';  
							$views['publish'] = sprintf(__('<a href="%s" '.$class.'>Published <span class="count">(%d)</span></a>', 'publish'), admin_url('edit.php?post_status=publish&post_type=page'), $result->found_posts); 
						}
						break;

					case 'draft':
						if($result->found_posts > 0){
							$class = ($status == 'draft') ? ' class="current"' : ''; 
							$views['draft'] = sprintf(__('<a href="%s" '.$class.'>Draft'. ((sizeof($result->posts) > 1) ? "s" : "") .' <span class="count">(%d)</span></a>', 'draft'), admin_url('edit.php?post_status=draft&post_type=page'), $result->found_posts);
						}
						break;
				
					case 'pending':
						if($result->found_posts > 0){
							$class = ($status == 'pending') ? ' class="current"' : ''; 
							$views['pending'] = sprintf(__('<a href="%s" '.$class.'>Pending <span class="count">(%d)</span></a>', 'pending'), admin_url('edit.php?post_status=pending&post_type=page'), $result->found_posts); 
						}
						break;

					case 'trash':
						if($result->found_posts > 0){
							$class = ($status == 'trash') ? ' class="current"' : ''; 
							$views['trash'] = sprintf(__('<a href="%s" '.$class.'>Bin <span class="count">(%d)</span></a>', 'trash'), admin_url('edit.php?post_status=trash&post_type=page'), $result->found_posts);  
						}
						break;
				}
			}
		}

		return $views;
	}

	function posts_where($where, $query) {
		global $pagenow, $wpdb;

		if (is_admin()){
			if ($pagenow == 'edit.php') {
				
				if($this->restrict_access()){
					//GET TEAMS CURRENT USER IS MEMBER OF
					$teams = $this->get_setting('users_teams');

					//FILTER THE LIST BASED ON TEAMS MEMBER OF
					if(count($teams)>0){
						$where .= " AND ($wpdb->postmeta.meta_key = 'rpg-team' AND $wpdb->postmeta.meta_value IN (";
						foreach ($teams as $team) {
							$where .= $team->term_id.',';
						}

						$where = rtrim($where,',');
						$where .= '))';
					}
				}
			}
		}

		return $where;
	}

	function posts_join($join, $query) {
		global $pagenow, $wpdb;

		if (is_admin()){
			if ($pagenow == 'edit.php') {
				if($this->restrict_access()){
					$join .= "LEFT JOIN $wpdb->postmeta ON $wpdb->posts.ID = $wpdb->postmeta.post_id ";
				}
			}
		}

		return $join;
	}

	function add_meta_boxes($post) {
		if($post->post_type==='page'){

			//EARLY HOOK INTO THE POST EDIT SCREENS - USE TO CHECK THAT CURRENT USER CAN VIEW THE PAGE
			if($this->restrict_access()){
				$canaccess = false;
				
				global $pagenow;

				if($pagenow==='post-new.php'){
					//NEW PAGE SO LET REQUEST THROUGH
					$canaccess = true;
				}else{
					//EDITING A PAGE SO CHECK CAN ACCESS
					$teams = $this->get_setting('users_teams');
					$post_teams = get_post_meta($post->ID, 'rpg-team');

					if(count($teams)>0){
						foreach ($teams as $team) {
							if(in_array($team->term_id, $post_teams)){
								$canaccess = true;
								break;
							}
						}
					}
				}

				//FAILED ACCESS CONTROL - REDIRECT BACK TO PAGE LISTING
				if(!$canaccess){
					wp_redirect(admin_url('/edit.php?post_type=page', 'http'), 302);
					exit;
				}
			}
			add_meta_box(
					'rpg-teams-access',
					__('Teams'),
					array($this, 'render_meta_box'),
					null,
					'side',
					'high'
				);

		}
	}

	function render_meta_box($object = null, $box = null){
		$output = '';
		$checked = '';
		$hasteams = false;

		//GET THE TEAMS THAT CURRENT USER HAS BEEN GRANTED ACCESS TO
		$teams = $this->get_setting('users_teams');
		
		//ANY TEAMS TO RENDER?
		if(count($teams)>0){

			//NEW PAGE?
			global $pagenow;

			switch($pagenow){
				case 'post-new.php':
					if(count($teams)===1){
						//ONLY 1 TEAM SO MAKE THE CHECKBOX CHECKED
						$checked = 'checked="checked"';
					}
					break;

				case 'post.php':
					//EXISTING PAGE - ENSURE CHECKBOXES FOR CURRENTLY ASSIGNED TEAMS ARE RENDERED CORRECTLY
					$post_teams = get_post_meta(get_post()->ID, 'rpg-team');
					if(count($post_teams)>0) $hasteams = true;
					break;
			}


			$output .= '<ul id="teamlist" class="form-no-clear">';

			foreach ($teams as $team) {

				if($hasteams){
					//DO WE NEED TO CHECK THE TEAM CHECKBOX?
					$checked = '';
					if(in_array($team->term_id, $post_teams)){
						$checked = 'checked="checked"';
					}
				}

				$output .= '<li id="rpg-'.$team->slug.'"><label class="selectit"><input value="'.$team->term_id.'" name="rpg-team'.$team->term_id.'" id="in-rpg-'.$team->slug.'" ' .$checked. ' type="checkbox">'.$team->name.'</label></li>';
			}

			//ADD IN NONCE FIELD?
			//$output .= wp_nonce_field(self::SET_GROUPS, self::NONCE, true, false);

			$output .= '</ul>';
		}else{
			$output .= 'No teams available';
		}

		echo $output;
		$this->bespoke_js_script();
	}

	function custom_column($column_name, $post_id) {
		$output = '';

		//GET ALL THE TEAMS THAT ARE ASSIGNED FOR THIS PAGE
		if($column_name==='teams-read'){
			$post_teams = get_post_meta($post_id, 'rpg-team');
			$teams = $this->get_setting('all_teams');

			foreach($post_teams as $team){
				foreach($teams as $masterteams){
					if($team == $masterteams['term_id']){
						$output.=$masterteams['name'].',';
					}
				}
			}

			$output = rtrim($output,',');

			if($output ===''){
				//NO TEAMS ASSIGNED TO THIS PAGE
				$output='&mdash;';
			}
		}

		echo $output;
	}

	function manage_columns($column_headers) {
		$column_headers['teams-read'] = sprintf(
			'<span title="%s">%s</span>',
			esc_attr(__('One or more teams granting access to pages.', 'teams')),
			esc_html(_x('Teams', 'Column header', 'teams'))
		);
		return $column_headers;
	}

	function remove_hooks(){
		remove_action('init', 'wp_register_default_user_group_taxonomy');
		remove_action('init', 'wp_register_default_user_type_taxonomy');
	}

	function add_roles(){
		//REMOVE OOTB ROLES
		remove_role('subscriber');
		remove_role('editor');
		remove_role('contributor');
		remove_role('author');

		//DEFINE CAPABILITIES
		$contentAuthorCaps = array(
			'read'						=> true,
			'edit_pages'				=> true,
			'ow_submit_to_workflow'		=> true,
		);

		$contentApproverCaps = array(
			'read'						=> true,
			'edit_pages'				=> true,
			'edit_others_pages'			=> true,
			'publish_pages'				=> true,
			'read_private_pages'		=> true,
			'delete_pages'				=> true,
			'delete_private_pages'		=> true,
			'delete_published_pages'	=> true,
			'delete_others_pages'		=> true,
			'edit_private_pages'		=> true,
			'edit_published_pages'		=> true,
			'upload_files'				=> true,
			'ow_reassign_task'			=> true,
			'ow_sign_off_step'			=> true,
			'ow_skip_workflow'			=> true,
			'ow_submit_to_workflow'		=> true,
			'ow_view_others_inbox'		=> true,
			'ow_view_reports'			=> true,
			'ow_view_workflow_history'	=> true,
		);

		$contentPublisherCaps = array(
			'read'						=> true,
			'edit_pages'				=> true,
			'edit_others_pages'			=> true,
			'publish_pages'				=> true,
			'read_private_pages'		=> true,
			'delete_pages'				=> true,
			'delete_private_pages'		=> true,
			'delete_published_pages'	=> true,
			'delete_others_pages'		=> true,
			'edit_private_pages'		=> true,
			'edit_published_pages'		=> true,
			'upload_files'				=> true,
			'ow_reassign_task'			=> true,
			'ow_sign_off_step'			=> true,
			'ow_skip_workflow'			=> true,
			'ow_submit_to_workflow'		=> true,
			'ow_view_others_inbox'		=> true,
			'ow_view_reports'			=> true,
			'ow_view_workflow_history'	=> true,
		);

		$contentAdminCaps = array(
			'read'						=> true,
			'edit_dashboard'			=> true,
			'edit_pages'				=> true,
			'edit_others_pages'			=> true,
			'publish_pages'				=> true,
			'read_private_pages'		=> true,
			'delete_pages'				=> true,
			'delete_private_pages'		=> true,
			'delete_published_pages'	=> true,
			'delete_others_pages'		=> true,
			'edit_private_pages'		=> true,
			'edit_published_pages'		=> true,
			'upload_files'				=> true,
			'manage_rpgsnippets'		=> true,
			'create_roles'				=> true,
			'create_users'				=> true,
			'delete_roles'				=> true,
			'delete_users'				=> true,
			'edit_roles'				=> true,
			'edit_users'				=> true,
			'list_roles'				=> true,
			'list_users'				=> true,
			'promote_users'				=> true,
			'remove_users'				=> true,
			'ow_reassign_task'			=> true,
			'ow_sign_off_step'			=> true,
			'ow_skip_workflow'			=> true,
			'ow_submit_to_workflow'		=> true,
			'ow_view_others_inbox'		=> true,
			'ow_view_reports'			=> true,
			'ow_view_workflow_history'	=> true,
		);

		$contentSnippets = array(
			'manage_rpgsnippets'		=> true,
			'read'						=> true,
		);

		//CREATE CUSTOM ROLES
		add_role('content_author', __('Content Author'), $contentAuthorCaps);
		add_role('content_approver', __('Content Approver'), $contentApproverCaps);
		add_role('content_publisher', __('Content Publisher'), $contentPublisherCaps);
		add_role('content_admin', __('Content Admin'), $contentAdminCaps);
		add_role('content_snippets', __('Content Snippets'), $contentSnippets);
	}

	function clean_unwanted_caps(){
		$delete_caps = array('ow_delete_workflow_history');
		global $wp_roles;
		foreach ($delete_caps as $cap) {
			foreach (array_keys($wp_roles->roles) as $role) {
				$wp_roles->remove_cap($role, $cap);
			}
		}
	}

    function register_user_taxonomy() {
        //IF CLASS NOT AVAILABLE BAIL
        if (!class_exists('WP_User_Taxonomy')){
            return;
        }

        //CREATE THE NEW USER TAXONOMY
        new WP_User_Taxonomy('content_team', 'users/content-team', array(
            'singular' => __('Team',  'rpgutils'),
            'plural'   => __('Teams', 'rpgutils'),
			'exclusive' => false,
       ));
    }

	function get_setting($name, $value = null){
		if(isset($this->settings[$name])) {
			$value = $this->settings[$name];
		}
		return $value;
	}

	function restrict_access(){
		//IF CURRENT USER HAS manage_options CAPABILITY THEN CAN SEE EVERYTHING
		$restrict = true;
		if(current_user_can('manage_options')) $restrict = false;
		return $restrict;
	}
}

function rpgutils() {
	global $rpgutils;
	
	if(!isset($rpgutils)) {
		$rpgutils = new rpgutils();
		$rpgutils->initialize();
	}
	
	return $rpgutils;
}

//KICK OFF
rpgutils();

endif;
?>