<?php
/**
 * CLI Version of the WordPress Importer
 * Author: Thorsten Ott
 */
define( 'CLI_WP_ROOT_DIRECTORY', dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ); // you need to adjust this to your path eventually
define( 'CLI_WP_DEFAULT_HOST', 'wp_trunk' ); // set this to the default wordpress domain that's used for initialization when --import_hostname is omitted

cli_import_set_hostname();

define( 'WP_IMPORTING', true );
define( 'WP_DEBUG', true );

if ( !file_exists( CLI_WP_ROOT_DIRECTORY . '/wp-load.php' ) ) {
	die( sprintf( "Please set CLI_WP_ROOT_DIRECTORY to the ABSPATH of your WordPress install. Could not find %s\n", CLI_WP_ROOT_DIRECTORY . '/wp-load.php' ) );
}

define( 'WP_LOAD_IMPORTERS', false );
ob_start();

require_once( CLI_WP_ROOT_DIRECTORY . '/wp-load.php' );
require_once( ABSPATH . 'wp-admin/includes/admin.php' );

define( 'WP_LOAD_IMPORTERS', true );
require_once( dirname( __FILE__ ) . '/wordpress-importer.php' );

ob_end_clean();

set_time_limit( 0 );
ini_set( 'memory_limit', '512m' );

class WordPress_CLI_Import extends WP_Import {
	public $args;
	private $validate_args = array();
	private $required_args = array();
	public $debug_mode = true;
	
	// Import Vars
	public $wxr_file = '';
	public $blog_id = 0;
	public $user_id = 0;
	public $blog_address = 0;
	public $fetch_attachments = false;
	//
	public $user_mapping;
	
	public function __construct() {
		$this->args = $this->get_cli_arguments();
		
		parent::__construct();
	}

	public function init() {
		if ( !$this->validate_args() ) {
			$this->debug_msg( "Problems with arguments" );
			exit;
		} 
		if ( !empty( $this->args->import_hostname ) ) {
			$this->dispatch();
		} else {
			$this->debug_msg( "Initializing Import Environment" );
			$this->args->import_hostname = $this->blog_address;
			foreach( $this->args as $key => $value )
				if ( 'blog' == $key )
					$args[] = "--$key=" . (int) $value;
				else 
					$args[] = "--$key=" . escapeshellarg( $value );	

			$command = "php " . __FILE__ . " " . implode( " ", (array) $args );
			$this->debug_msg( "execute: $command" );
			system( $command );
		}
	}

	public function dispatch() {
		if( 'true' == $this->args->attachments )
			$this->fetch_attachments = true;
		else 
			$this->fetch_attachments = false;
			
		$this->blog_id = $this->set_blog( $this->args->blog );
		$this->user_id = $this->set_user( $this->args->user );

		$this->wxr_file = $this->args->file;
		
		$_GET = array( 'import' => 'wordpress', 'step' => 2 );
		$author_in = $user_select = array();
		foreach( $this->user_mapping as $in => $out ) {
			$author_in[] = sanitize_user( $in, true );
			$user_select[] = $out;
		}
		$_POST = array(
						'imported_authors' 	=> $author_in,
						'user_map' 	=> $user_select,
						'fetch_attachments' => $this->fetch_attachments,
		);
		
		if ( true == $this->debug_mode ) {
			define( 'WP_IMPORT_DEBUG', true );
			define( 'IMPORT_DEBUG', true );
		}
		
		$this->debug_msg( "Starting Import" );
		$this->import( $this->wxr_file, $this->fetch_attachments );
	}

	public function debug_msg( $msg ) {
		$msg = date( "Y-m-d H:i:s : " ) . $msg;
		if ( $this->debug_mode ) 
			echo $msg . "\n";
		else 
			error_log( $msg );
	}
	
	public function set_required_arg( $name, $description='' ) {
		$this->required_args[$name] = $description;
	}

	public function set_argument_validation( $name_match, $value_match, $description='argument validation error' ) {
		$this->validate_args[] = array( 'name_match' => $name_match, 'value_match' => $value_match, 'description' => $description );
	}

	
	private function validate_args() {
		$result = true;
		$this->debug_msg( "Validating arguments" );
		if ( empty( $_SERVER['argv'][1] ) && !empty( $this->required_args ) ) {
			$this->show_help();
			$result = false;
		} else {
			foreach( $this->required_args as $name => $description ) {
				if ( !isset( $this->args->$name) ) {
					$this->raise_required_argument_error( $name, $description );
					$result = false;
				}
			}
		}
		foreach( $this->validate_args as $validator ) {
			foreach( $this->args as $name => $value ) {
				$name_match_result = preg_match( $validator['name_match'], $name );
				if ( ! $name_match_result ) {
					continue;
				} else {
					$value_match_result = $this->dispatch_argument_validator( $validator['value_match'], $value );
					if ( ! $value_match_result ) {
						$this->raise_argument_error( $name, $value, $validator );
						$result = false;
						continue;
					}
				}
			}
		}

		return $result;
	}

	private function dispatch_argument_validator( $match, $value ) {
		$match_result = false;
		if ( is_callable( array( &$this, $match ) ) ) {
			$_match_result = call_user_func( array( &$this, $match ), $value );
		} else if ( is_callable( $match ) ) {
			$_match_result = call_user_func( $match, $value );
		} else {
			$_match_result = preg_match( $match, $value );
		}
		return $_match_result;
	}

	private function raise_argument_error( $name, $value, $validator ) {
		printf( "Validation of %s with value %s failed: %s\n", $name, $value, $validator['description'] );
	}

	private function raise_required_argument_error( $name, $description ) {
		printf( "Argument --%s is required: %s\n", $name, $description );
	}

	private function show_help() {
		$example = "php " . __FILE__ . " --blog=bugster --file=/home/wpdev/test-import.xml --attachments=true --user=tottdev --author_mapping=/home/wpdev/author_map.php";
		printf( "Please call the script with the following arguments: \n%s\n", $example );
		foreach( $this->required_args as $name => $description )
			$msg .= $this->raise_required_argument_error( $name, $description );
		
	}
	
	private function cli_init_blog( $blog_id ) {
		if ( !is_numeric( $blog_id ) ) {
			$this->debug_msg( sprintf( "please provide the numeric blog_id for %s", $blog_id ) );
			die();
		}
		
		$home_url = str_replace( 'http://', '', get_home_url( $blog_id ) );
		$home_url = preg_replace( '#/$#', '', $home_url );
		$this->blog_address = array_shift( explode( "/", $home_url ) );

		if ( false <> $this->blog_address ) {
			$this->debug_msg( sprintf( "the blog_address we found is %s (%d)", $this->blog_address, $blog_id ) );
			$this->args->blog = $blog_id;
			switch_to_blog( (int) $blog_id );
			return true;
		} else {
			$this->debug_msg( sprintf( "could not get a blog_address for this blog_id: %s (%s)", var_export( $this->blog_address, true ), var_export( $blog_id, true ) ) );
			die();
		}
	}

	private function cli_set_user( $user_id ) {
		if ( is_numeric( $user_id ) ) {
			$user_id = (int) $user_id;
		} else {
			$user_id = (int) username_exists( $user_id );
		}
		if ( !$user_id || !wp_set_current_user( $user_id ) ) {
			return false;
		}

		$current_user = wp_get_current_user();
		return $user_id;
	}
	
	private function get_cli_arguments() {
		$_ARG = new StdClass;
		$argv = $_SERVER['argv'];
		array_shift( $argv );
		foreach ( $argv as $arg ) {
			if ( preg_match( '#--([^=]+)=(.*)#', $arg, $reg ) )
				$_ARG->$reg[1] = $reg[2];
			elseif( preg_match( '#-([a-zA-Z0-9])#', $arg, $reg ) )
				$_ARG->$reg[1] = 'true';
		}
		return $_ARG;
	}
	
	private function parse_wp_authors() {
		$this->debug_msg( sprintf( "parsing authors from wxr file %s", $this->args->file ) );
		$this->file = $this->args->file;
		$is_wxr_file = $this->parse( $this->file );
		if ( !is_wp_error( $is_wxr_file ) ) {
			$this->get_authors_from_import( $is_wxr_file );
			if ( empty( $this->authors ) ) {
				$this->debug_msg( sprintf( "could not parse authors from wxr file %s", $this->args->file ) );
				return false;
			} else {
				return $this->authors;
			}
		} else {
			$this->debug_msg( sprintf( "could not parse authors from wxr file %s", $this->args->file, $is_wxr_file->get_error_message() ) );
		}
		return false;
	}

	private function validate_author_map( $mapping_file_name ) {
		// return true; // uncomment this if you don't have any authors
		if ( empty( $mapping_file_name ) ) {
			$this->debug_msg( "no mapping provided, all smooth!" );
			return true;
		}
		
		if ( !$wxr_authors = $this->parse_wp_authors() ) {
			return false;
		} 
		
		$blog_users = array();
		$_blog_users = get_users(); 
		foreach( $_blog_users as $blog_user ) {
			$blog_users[$blog_user->ID] = $blog_user;
		}

		if ( !file_exists( $mapping_file_name ) ) {
			$this->debug_msg( sprintf( "mapping file %s does not exist", $mapping_file_name ) );
			if ( touch( $mapping_file_name ) ) {
				$default_user = $this->args->user;
				
				foreach( $wxr_authors as $wp_author => $wp_author_data ) {
					$user_suggestion = $this->suggest_user( sanitize_user( $wp_author, true ), $blog_users );
					if ( empty( $user_suggestion ) )
						$user_suggestion = $default_user;
						
					$tmp_mapping[ sanitize_user( $wp_author, true ) ] = $user_suggestion;
				}
			}
			if ( !empty( $tmp_mapping ) ) {
				$user_string = "array( \n";
				
				foreach( $tmp_mapping as $from_user => $to_user ) {
					if ( isset( $blog_users[$to_user] ) && $to_user <> $default_user )
						$comment = sprintf( "\t// %s, %s, %s", $blog_users[$to_user]->display_name, $blog_users[$to_user]->user_email, $blog_users[$to_user]->user_login );
					else 
						$comment = sprintf( "\t// default: %s", $blog_users[$to_user]->user_login );
					$user_string .= sprintf( "\t'%s'\t\t\t => \t%d,%s\n", $from_user, $to_user, $comment );
				}
				
				$user_string .= ");";
				$content = "<?php\n\n\$cli_user_map = " . $user_string . "\n\n";
				file_put_contents( $mapping_file_name, $content );
				$this->debug_msg( sprintf( "we created a default mapping file %s for you. please edit this before you continue", $mapping_file_name ) );
				die();
			}
			return false;
		}
		
		require_once( $mapping_file_name );
		if ( !isset( $cli_user_map ) || empty( $cli_user_map ) ) {
			$this->debug_msg( sprintf( "define \$cli_user_map = array( <old_username/id> => <new_user_name/id> ); in %s", $mapping_file_name ) );
			return false;
		}
		
		
		$result = true;
		$this->user_mapping = array();
		foreach( (array) $cli_user_map as $from_user => $to_user ) {
			if ( is_numeric( $to_user ) ) {
				$field = 'id';
			} else if ( is_email( $to_user ) ) {
				$field = 'email';
			} else {
				$field = 'login';
			}
			
			$user = get_user_by( $field, $to_user );
			if ( $user && !is_wp_error( $user ) ) {
				$this->debug_msg( sprintf( "found matching user %s (%d) for user %s matching %s => %d", $user->user_email, $user->ID, $to_user, $from_user, $user->ID ) );
				$this->user_mapping[ sanitize_user( $from_user, true ) ] = $user->ID;
			} else {
				$this->debug_msg( sprintf( "could not find a target user_id for user %s", $to_user ) );
				$result = false;
			}
		}
		
		foreach( $wxr_authors as $author_name => $user_data ) {
			$author_name = sanitize_user( $author_name );
			if ( !in_array( $author_name, array_keys( $cli_user_map ) ) ) {
				$this->debug_msg( sprintf( "the user map does not contain an assignment for %s", $author_name ) );
				$result = false;
			}
		}
			
		return $result;
	}

	
	
	public function backfill_attachment_urls() {
		global $wpdb;
			
		do_action( 'wp_import_check_memory' );
		// make sure we do the longest urls first, in case one is a substring of another
		uksort( $this->url_remap, array(&$this, 'cmpr_strlen') );
		$replacements = array();
		foreach ( $this->url_remap as $from_url => $to_url ) {
			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", "%$from_url%" ) );
			if ( count( $post_ids ) >  0 ) {
				foreach ( $post_ids as $id ) {
					$replacements[$id][$from_url] = $to_url;
				}
			}
		}
		foreach( $replacements as $post_id => $post_replacements ) {
			$post = get_post( $post_id );
			uksort( $post_replacements, array(&$this, 'cmpr_strlen') );
			$new_post_content = str_replace( array_keys( $post_replacements ), array_values( $post_replacements ), $post->post_content );
			if ( $new_post_content <> $post->post_content )
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = %s WHERE ID = %d", $new_post_content, $post_id ) );
		}
	}
	
	private function suggest_user( $author_in, $users ) {
		$shortest = -1;
		$shortestavg = array();
	
		$threshold = floor( ( strlen( $author_in ) / 100 ) * 10 ); // 10 % of the strlen are valid
		foreach ( $users as $user ) {
			$levs[] = levenshtein( $author_in, $user->display_name );
			$levs[] = levenshtein( $author_in, $user->user_login );
			$levs[] = levenshtein( $author_in, $user->user_email );
			$levs[] = levenshtein( $author_in, array_shift( explode( "@", $user->user_email ) ) );
			arsort( $levs );
			$lev = array_pop( $levs );
			if ( 0 == $lev ) {
				$closest = $user->user_id;
				$shortest = 0;
				break;
			}
	
			if ( ( $lev <= $shortest || $shortest < 0 ) && $lev <= $threshold ) {
				$closest  = $user->user_id;
				$shortest = $lev;
			}
			$shortestavg[] = $lev;
		}	
		// in case all usernames have a common pattern
		if ( $shortest > ( array_sum( $shortestavg ) / count( $shortestavg ) ) )
			return false;
		return $closest;
	}
}

function cli_import_set_hostname() {
	foreach ( $_SERVER['argv'] as $arg ) {
		if ( preg_match( '#--import_hostname=(.*)#', $arg, $reg ) ) {
			$_SERVER['HTTP_HOST'] = $reg[1];
			return;
		}
	}
	$_SERVER['HTTP_HOST'] = CLI_WP_DEFAULT_HOST;
}

$importer = new WordPress_CLI_Import;
$importer->set_required_arg( 'blog', 'Blog ID of the blog you like to import to' );
$importer->set_required_arg( 'file', 'Full Path to WXR import file' );
$importer->set_required_arg( 'attachments', 'Import attachments (true/false)' );
$importer->set_required_arg( 'user', 'Username/ID the import should run as' );
$importer->set_required_arg( 'author_mapping', 'empty or php file with User mapping array $cli_user_map = array( <old_user> => <new_user_name/email/id> ); defined. if the file does not exist it will be created for you' );

$importer->set_argument_validation( '#^blog$#', 'cli_init_blog', 'blog invalid' );
$importer->set_argument_validation( '#^user$#', 'cli_set_user', 'user invalid' );
$importer->set_argument_validation( '#^attachments$#', '#^(true|false)$#', 'attachments value invalid' );
$importer->set_argument_validation( '#^file$#', 'file_exists', 'import file does not exist' );
$importer->set_argument_validation( '#^author_mapping$#', 'validate_author_map', 'author mapping invalid' );

$importer->init();
