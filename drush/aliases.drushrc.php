<?php
// Don't change anything here, it's magic!

global $project_name;

use Symfony\Component\Yaml\Yaml;

// For CI: allow to completely disable amazee.io alias loading
if (getenv('LAGOON_DISABLE_ALIASES')) {
  drush_log('LAGOON_DISABLE_ALIASES is set, bailing out of loading lagoon aliases');
  return;
}

// Allow to override project via environment variable
if (getenv('LAGOON_OVERRIDE_SITEGROUP')) {
  $project_name = getenv('LAGOON_OVERRIDE_SITEGROUP');
  drush_log("LAGOON_OVERRIDE_SITEGROUP is set, using '$project_name' as project");
}

if (empty($project_name)) {
  // You know nothing, John amazee.io
  $lagoonyml_path = $lagoonyml = $project_name = FALSE;

  drush_log('Finding Drupal Root');

  if ( DRUSH_VERSION >= 9 ) {
     $_d = new \Drush\Drush();
     $path = $_d->getContainer()->get('bootstrap.manager')->getRoot();
     // _drush_shift_path_up() was part of D8, we define it for D9, borrowed from DrupalFinder->shiftPathUp()
     if(!function_exists("_drush_shift_path_up")) {
        function _drush_shift_path_up($path) {
            $parent = dirname($path);
            return in_array($parent, ['.', $path]) ? false : $parent;
        }
     }
   } else {
     $path = drush_locate_root(drush_get_option('root')) ?: getcwd(); // trying to find the main root folder of drupal, if that fails, just the current folder
   }

  // No project name could be found, let's search for it via the .lagoon.yml file
  drush_log("Starting to search for .lagoon.yml file to extract project name within '$path' and parent directories");

  // Borrowed from drush_locate_root() - thank you
  foreach (array(TRUE, FALSE) as $follow_symlinks) {
    if ($follow_symlinks && is_link($path)) {
      $path = realpath($path);
    }
    // Check the start path.
    if (file_exists("$path/.lagoon.yml")) {
      $lagoonyml_path = "$path/.lagoon.yml";
      break;
    }
    else {
      // Move up dir by dir and check each.
      while ($path = _drush_shift_path_up($path)) {
        if ($follow_symlinks && is_link($path)) {
          $path = realpath($path);
        }
        if (file_exists("$path/.lagoon.yml")) {
          $lagoonyml_path = "$path/.lagoon.yml";
          break 2;
        }
      }
    }
  }

  // An .lagoon.yml file has been found, let's try to load the project from it.
  if ($lagoonyml_path) {
    drush_log("Using .lagoon.yml file at: '$lagoonyml_path'");

    $lagoonyml = Yaml::parse( file_get_contents($lagoonyml_path) );

    if ($lagoonyml['project']) {
      $project_name = $lagoonyml['project'];
      drush_log("Discovered project name '$project_name' from .lagoon.yml file");
    }
  } else {
    drush_log('Could not find .lagoon.yml file.');
  }

  // Sitegroup still not defined, trhow a warning.
  if ($project_name === FALSE) {
    drush_log('ERROR: Could not discover project name, you should define it inside your .lagoon.yml file', 'warning');
    exit;
  }
}

// Some special things to make sure Jenkins does never cache the aliases.
$suffix = getenv('JENKINS_HOME') ? '_' . getenv('BUILD_NUMBER') : '';
$cid = "lagoon_aliases_$project_name$suffix";

// Try to pull the aliases from the cache.
$cache = drush_cache_get($cid);

// Drush does not respect the cache expire, so we need to check it ourselves.
if (isset($cache->data) && time() < $cache->expire && getenv('LAGOON_IGNORE_DRUSHCACHE') === FALSE) {
  drush_log('Hit amazee.io project cache');
  $aliases = $cache->data;

  if (getenv('LAGOON_DEBUG')) {
    drush_log("Aliases found in cache: " . var_export($aliases, true));
  }

  return;
}

// The aliases haven't been cached yet. Load them from the API.
drush_log("Loading site configuration for '$project_name' from the API.");

$query = sprintf('{
  project:projectByName(name: "%s") {
    environments {
      name
      openshift_projectname
    }
    openshift {
      ssh_host
      ssh_port
    }
  }
}
', $project_name);

$api = getenv('LAGOON_OVERRIDE_API_ENDPOINT') ? getenv('LAGOON_OVERRIDE_API_ENDPOINT') : 'http://localhost:3000/graphql';
$jwt_token = getenv('LAGOON_OVERRIDE_JWT_TOKEN') ? getenv('LAGOON_OVERRIDE_JWT_TOKEN') : 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJyb2xlIjoiYWRtaW4iLCJpc3MiOiJhdXRoLXNzaCBCYXNoIEdlbmVyYXRvciIsImF1ZCI6ImFwaS5kZXYiLCJzdWIiOiJhdXRoLXNlcnZlciIsImlhdCI6MTUwOTIyMzE2N30.gGjSuPdmUchMPzGvg_xdUfVUDXtOqm-p1KmlsPFPOHc';

drush_log("Using $api as amazee.io API endpoint");

$curl = curl_init($api);

// Build up the curl options for the GraphQL query. When using the content type
// 'application/json', graphql-express expects the query to be in the json
// encoded post body beneath the 'query' property.
curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($curl, CURLOPT_POST, TRUE);
curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "Authorization: Bearer $jwt_token"]);
curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
  'query' => $query,
)));

$response = curl_exec($curl);

if (getenv('LAGOON_DEBUG')) {
  drush_log("Response from api: " . var_export($response, true));
}

// Check if the curl request succeeded.
if ($response === FALSE) {
  $info = var_export(curl_getinfo($curl), TRUE);
  $error = curl_error($curl);
  curl_close($curl);


  drush_log($info, 'error');
  drush_log($error, 'error');
  exit;
}

curl_close($curl);
$response = json_decode($response);

if (getenv('LAGOON_DEBUG')) {
  drush_log("Decoded response from api: " . var_export($response, true));
}

// Check if the query returned any data for the requested site group.
if (empty($response->data->project->environments)) {
  drush_log("Curl request didn't return any environments for the given site group '$project_name'.", 'warning');
  return;
}

$environments = $response->data->project->environments;
$openshift = $response->data->project->openshift;
// Default server definition, which has no site specific elements
$defaults = [
  'command-specific' => [
    'sql-sync' => [
      'no-ordered-dump' => TRUE
    ],
  ],
  'ssh-options' => "-o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no",
];

$aliases = array_reduce($environments, function ($carry, $environment) use ($defaults) {
  $site_name = $environment->name;
  $site_host = 'localhost';

  $alias = [];

  $alias[$site_name] = [
    'remote-host' => "$openshift->ssh_host",
    'remote-user' => 'lagoon',
    'ssh-options' => "-p $openshift->ssh_port"
  ] + $defaults;

  return $carry + $alias;
}, []);

if (getenv('LAGOON_DEBUG')) {
  drush_log("Generated aliases: " . var_export($aliases, true));
}

// Caching the aliases for 10 minutes.
drush_cache_set($cid, $aliases, 'default', time() + 600);

