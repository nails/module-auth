<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Name:		Auth [reset password]
 *
 * Description:	This controller handles the resetting of a user's temporary password
 *
 **/

/**
 * OVERLOADING NAILS' AUTH MODULE
 *
 * Note the name of this class; done like this to allow apps to extend this class.
 * Read full explanation at the bottom of this file.
 *
 **/

require_once '_auth.php';

class NAILS_Reset_Password extends NAILS_Auth_Controller
{
	/**
	 * Constructor
	 *
	 * @access	public
	 * @param	none
	 * @return	void
	 **/
	public function __construct()
	{
		parent::__construct();

		// --------------------------------------------------------------------------

		//	If user is logged in they shouldn't be accessing this method
		if ( $this->user_model->is_logged_in() ) :

			$this->session->set_flashdata( 'error', lang( 'auth_no_access_already_logged_in', active_user( 'email' ) ) );
			redirect( '/' );

		endif;
	}


	// --------------------------------------------------------------------------


	/**
	 * Validate the supplied assets and if valid present the user with a reset form
	 *
	 * @access	public
	 * @param	int		$id		The ID fo the user to reset
	 * @param	strgin	hash	The hash to validate against
	 * @return	void
	 **/
	private function _validate( $id, $hash )
	{
		//	Check auth credentials
		$_user = $this->user_model->get_by_id( $id );

		// --------------------------------------------------------------------------

		if ( $_user !== FALSE && isset( $_user->salt ) && $hash == md5( $_user->salt ) ) :

			//	Valid combination
			if ( $this->input->post() ) :

				// Validate data
				$this->load->library( 'form_validation' );

				// --------------------------------------------------------------------------

				//	Define rules
				$this->form_validation->set_rules( 'new_password',	'password',		'required|matches[confirm_pass]' );
				$this->form_validation->set_rules( 'confirm_pass',	'confirmation',	'required' );

				// --------------------------------------------------------------------------

				//	Set custom messages
				$this->form_validation->set_message( 'required',	lang( 'fv_required' ) );
				$this->form_validation->set_message( 'matches',		lang( 'fv_matches' ) );

				// --------------------------------------------------------------------------

				//	Run validation
				if ( $this->form_validation->run() ) :

					//	Validated, update user and login.
					$_data['forgotten_password_code']	= NULL;
					$_data['temp_pw']					= NULL;
					$_data['password']					= $this->input->post( 'new_password' );

					$_remember							= (bool) $this->input->get( 'remember' );

					//	Reset the password
					if ( $this->user_model->update( $id, $_data ) ) :

						//	Log the user in
						switch( APP_NATIVE_LOGIN_USING ) :

							case 'EMAIL' :

								$_login = $this->auth_model->login( $_user->email, $this->input->post( 'new_password' ), $_remember );

							break;

							// --------------------------------------------------------------------------

							case 'USERNAME' :

								$_login = $this->auth_model->login( $_user->username, $this->input->post( 'new_password' ), $_remember );

							break;

							// --------------------------------------------------------------------------

							case 'BOTH' :
							default :

								$_login = $this->auth_model->login( $_user->email, $this->input->post( 'new_password' ), $_remember );

							break;

						endswitch;

						if ( $_login ) :

							if ( $this->config->item( 'auth_two_factor_enable' ) ) :

								$_query	= array();

								if ( $this->input->get( 'return_to' ) ) :

									$_query['return_to'] = $this->input->get( 'return_to' );

								endif;

								if ( $_remember ) :

									$_query['remember'] = $_remember;

								endif;

								$_query = $_query ? '?' . http_build_query( $_query ) : '';

								//	Login was successful, redirect to the security questions page
								redirect( 'auth/security_questions/' . $_login['user_id'] . '/' . $_login['two_factor_auth']['salt'] . '/' . $_login['two_factor_auth']['token'] . $_query );

							else :

								//	Say hello
								if ( $_login['last_login'] ) :

									$this->load->helper( 'date' );

									$_last_login = $this->config->item( 'auth_show_nicetime_on_login' ) ? nice_time( strtotime( $_login['last_login'] ) ) : user_datetime( $_login['last_login'] );

									if ( $this->config->item( 'auth_show_last_ip_on_login' ) ) :

										$this->session->set_flashdata( 'message', lang( 'auth_login_ok_welcome_with_ip', array( $_login['first_name'], $_last_login, $_login['last_ip'] ) ) );

									else :

										$this->session->set_flashdata( 'message', lang( 'auth_login_ok_welcome', array( $_login['first_name'], $_last_login ) ) );

									endif;

								else :

									$this->session->set_flashdata( 'message', lang( 'auth_login_ok_welcome_notime', array( $_login['first_name'] ) ) );

								endif;

								//	Log user in and forward to wherever they need to go
								if ( $this->input->get( 'return_to' ) ):

									redirect( $this->input->get( 'return_to' ) );
									return;

								elseif ( $_user->group_homepage ) :

									redirect( $_user->group_homepage );
									return;

								else :

									redirect( '/' );
									return;

								endif;

							endif;

						else :

							$this->data['error'] = lang( 'auth_forgot_reset_badlogin', site_url( 'auth/login' ) );

						endif;

					else :

						$this->data['error'] = lang( 'auth_forgot_reset_badupdate', $this->user_model->last_error() );

					endif;

				else:

					$this->data['error'] = lang( 'fv_there_were_errors' );

				endif;

			endif;

			// --------------------------------------------------------------------------

			//	Set data
			$this->data['page']->title	= lang( 'auth_title_reset' );

			$this->data['auth']			= new stdClass();
			$this->data['auth']->id		= $id;
			$this->data['auth']->hash	= $hash;

			$this->data['return_to']	= $this->input->get( 'return_to' );
			$this->data['remember']		= $this->input->get( 'remember' );

			$this->data['message']		= lang( 'auth_forgot_temp_message' );

			// --------------------------------------------------------------------------

			//	Load the views
			$this->load->view( 'structure/header',			$this->data );
			$this->load->view( 'auth/password/change_temp',	$this->data );
			$this->load->view( 'structure/footer',			$this->data );

			return;

		endif;

		// --------------------------------------------------------------------------

		show_404();
	}


	// --------------------------------------------------------------------------


	/**
	 * Route requests to the right method
	 *
	 * @access	public
	 * @param	string	$id	the ID of the user to reset, as per the URL
	 * @return	void
	 **/
	public function _remap( $id )
	{
		$this->_validate( $id, $this->uri->segment( 4 ) );
	}
}


// --------------------------------------------------------------------------


/**
 * OVERLOADING NAILS' AUTH MODULE
 *
 * The following block of code makes it simple to extend one of the core auth
 * controllers. Some might argue it's a little hacky but it's a simple 'fix'
 * which negates the need to massively extend the CodeIgniter Loader class
 * even further (in all honesty I just can't face understanding the whole
 * Loader class well enough to change it 'properly').
 *
 * Here's how it works:
 *
 * CodeIgniter instantiate a class with the same name as the file, therefore
 * when we try to extend the parent class we get 'cannot redeclare class X' errors
 * and if we call our overloading class something else it will never get instantiated.
 *
 * We solve this by prefixing the main class with NAILS_ and then conditionally
 * declaring this helper class below; the helper gets instantiated et voila.
 *
 * If/when we want to extend the main class we simply define NAILS_ALLOW_EXTENSION
 * before including this PHP file and extend as normal (i.e in the same way as below);
 * the helper won't be declared so we can declare our own one, app specific.
 *
 **/

if ( ! defined( 'NAILS_ALLOW_EXTENSION' ) ) :

	class Reset_Password extends NAILS_Reset_Password
	{
	}

endif;

/* End of file reset_password.php */
/* Location: ./application/modules/auth/controllers/reset_password.php */