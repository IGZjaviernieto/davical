<?php
/**
* @package rscds
* @author Andrew McMillan <andrew@catalyst.net.nz>
* @copyright Catalyst .Net Ltd
* @license   http://gnu.org/copyleft/gpl.html GNU GPL v2
*/

// Ensure the configuration starts out as an empty object.
unset($c);

// Default some of the configurable values
$c->sysabbr     = 'rscds';
$c->admin_email = 'andrew@catalyst.net.nz';
$c->system_name = "Really Simple CalDAV Store";
$c->domain_name = $_SERVER['SERVER_NAME'];
// $c->http_auth_mode = "Basic";
$c->save_time_zone_defs = true;
$c->collections_always_exist = true;
$c->home_calendar_name = 'home';
$c->enable_row_linking = true;
// $c->default_locale = array('es_MX', 'es_MX.UTF-8', 'es');
// $c->local_tzid = 'Pacific/Auckland';  // Perhaps we should read from /etc/timezone - I wonder how standard that is?
$c->default_locale = "en_NZ";
$c->base_url = preg_replace("#/[^/]+\.php.*$#", "", $_SERVER['SCRIPT_NAME']);
$c->base_directory = preg_replace("#/[^/]*$#", "", $_SERVER['DOCUMENT_ROOT']);

$c->stylesheets = array( $c->base_url."/rscds.css" );
$c->images      = $c->base_url . "/images";

// Ensure that ../inc is in our included paths as early as possible
set_include_path( '../inc'. PATH_SEPARATOR. get_include_path());

// Kind of private configuration values
$c->total_query_time = 0;

$c->dbg = array();

// Utilities
require_once("AWLUtilities.php");

/**
* Calculate the simplest form of reference to this page, excluding the PATH_INFO following the script name.
*/
$c->protocol_server_port_script = sprintf( "%s://%s%s%s", (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'? 'https' : 'http'), $_SERVER['SERVER_NAME'],
                 (
                   ( (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 'on') && $_SERVER['SERVER_PORT'] == 80 )
                           || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' && $_SERVER['SERVER_PORT'] == 443 )
                   ? ''
                   : ':'.$_SERVER['SERVER_PORT']
                 ),
                 $_SERVER['SCRIPT_NAME'] );

@dbg_error_log( "LOG", "==========> method =%s= =%s= =%s= =%s= =%s=", $_SERVER['REQUEST_METHOD'], $c->protocol_server_port_script, $_SERVER['PATH_INFO'], $c->base_url, $c->base_directory );

init_gettext( 'rscds', '../locale' );

if ( file_exists("/etc/rscds/".$_SERVER['SERVER_NAME']."-conf.php") ) {
  include_once("/etc/rscds/".$_SERVER['SERVER_NAME']."-conf.php");
}
else if ( file_exists("../config/config.php") ) {
  include_once("../config/config.php");
}
else {
  include_once("rscds_configuration_missing.php");
  exit;
}
if ( !isset($c->page_title) ) $c->page_title = $c->system_name;

/**
* Now that we have loaded the configuration file we can switch to a
* default site locale.  This may be overridden by each user.
*/
awl_set_locale($c->default_locale);

/**
* Work out our version
*
*/
$c->code_version = 0;
$c->version_string = '0.8.0+1'; // The actual version # is replaced into that during the build /release process
if ( isset($c->version_string) && preg_match( '/(\d+)\.(\d+)\.(\d+)(.*)/', $c->version_string, $matches) ) {
  $c->code_major = $matches[1];
  $c->code_minor = $matches[2];
  $c->code_patch = $matches[3];
  $c->code_version = (($c->code_major * 1000) + $c->code_minor).".".intval($c->code_patch);
}
dbg_error_log("caldav", "Version %s (%d.%d.%d) == %s", $c->code_pkgver, $c->code_major, $c->code_minor, $c->code_patch, $c->code_version);
// header( sprintf("Server: %s/%d.%d", $c->code_pkgver, $c->code_major, $c->code_minor) );
header( sprintf('X-RSCDS-Version: RSCDS/%d.%d.%d; DB/%d.%d.%d', $c->code_major, $c->code_minor, $c->code_patch, $c->schema_major, $c->schema_minor, $c->schema_patch) );

/**
* Force the domain name to what was in the configuration file
*/
$_SERVER['SERVER_NAME'] = $c->domain_name;

include_once("PgQuery.php");

$c->schema_version = 0;
$qry = new PgQuery( "SELECT schema_major, schema_minor, schema_patch FROM awl_db_revision ORDER BY schema_id DESC LIMIT 1;" );
if ( $qry->Exec("always") && $row = $qry->Fetch() ) {
  $c->schema_version = doubleval( sprintf( "%d%03d.%03d", $row->schema_major, $row->schema_minor, $row->schema_patch) );
  $c->schema_major = $row->schema_major;
  $c->schema_minor = $row->schema_minor;
  $c->schema_patch = $row->schema_patch;
}

$_known_users = array();
function getUserByName( $username ) {
  // Provide some basic caching in case this ends up being overused.
  if ( isset( $_known_users[$username] ) ) return $_known_users[$username];

  $qry = new PgQuery( "SELECT * FROM usr WHERE lower(username) = lower(?) ", $username );
  if ( $qry->Exec('always',__LINE__,__FILE__) && $qry->rows == 1 ) {
    $_known_users[$username] = $qry->Fetch();
    return $_known_users[$username];
  }

  return false;
}


/**
 * Return the HTTP status code description for a given code. Hopefully
 * this is an efficient way to code this.
 * @return string The text for a give HTTP status code, in english
 */
function getStatusMessage($status) {
  switch( $status ) {
    case 100:  $ans = "Continue";                             break;
    case 101:  $ans = "Switching Protocols";                  break;
    case 200:  $ans = "OK";                                   break;
    case 201:  $ans = "Created";                              break;
    case 202:  $ans = "Accepted";                             break;
    case 203:  $ans = "Non-Authoritative Information";        break;
    case 204:  $ans = "No Content";                           break;
    case 205:  $ans = "Reset Content";                        break;
    case 206:  $ans = "Partial Content";                      break;
    case 207:  $ans = "Multi-Status";                         break;
    case 300:  $ans = "Multiple Choices";                     break;
    case 301:  $ans = "Moved Permanently";                    break;
    case 302:  $ans = "Found";                                break;
    case 303:  $ans = "See Other";                            break;
    case 304:  $ans = "Not Modified";                         break;
    case 305:  $ans = "Use Proxy";                            break;
    case 307:  $ans = "Temporary Redirect";                   break;
    case 400:  $ans = "Bad Request";                          break;
    case 401:  $ans = "Unauthorized";                         break;
    case 402:  $ans = "Payment Required";                     break;
    case 403:  $ans = "Forbidden";                            break;
    case 404:  $ans = "Not Found";                            break;
    case 405:  $ans = "Method Not Allowed";                   break;
    case 406:  $ans = "Not Acceptable";                       break;
    case 407:  $ans = "Proxy Authentication Required";        break;
    case 408:  $ans = "Request Timeout";                      break;
    case 409:  $ans = "Conflict";                             break;
    case 410:  $ans = "Gone";                                 break;
    case 411:  $ans = "Length Required";                      break;
    case 412:  $ans = "Precondition Failed";                  break;
    case 413:  $ans = "Request Entity Too Large";             break;
    case 414:  $ans = "Request-URI Too Long";                 break;
    case 415:  $ans = "Unsupported Media Type";               break;
    case 416:  $ans = "Requested Range Not Satisfiable";      break;
    case 417:  $ans = "Expectation Failed";                   break;
    case 422:  $ans = "Unprocessable Entity";                 break;
    case 423:  $ans = "Locked";                               break;
    case 424:  $ans = "Failed Dependency";                    break;
    case 500:  $ans = "Internal Server Error";                break;
    case 501:  $ans = "Not Implemented";                      break;
    case 502:  $ans = "Bad Gateway";                          break;
    case 503:  $ans = "Service Unavailable";                  break;
    case 504:  $ans = "Gateway Timeout";                      break;
    case 505:  $ans = "HTTP Version Not Supported";           break;
    default:   $ans = "Unknown HTTP Status Code '$status'";
  }
  return $ans;
}


?>