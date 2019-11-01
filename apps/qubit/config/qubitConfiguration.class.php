<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

class qubitConfiguration extends sfApplicationConfiguration
{
  const
    // Required format: x.y.z
    VERSION = '2.1.0';

  public function responseFilterContent(sfEvent $event, $content)
  {
    ProjectConfiguration::getActive()->loadHelpers('Javascript');

    $drmc = array();
    foreach (sfConfig::getAll() as $key => $value)
    {
      if (strpos($key, 'app_drmc_') === 0)
      {
        $key = substr($key, 9);

        // Do not include SS information
        if (strpos($key, 'ss_') === 0)
        {
          continue;
        }

        $drmc[$key] = $value;
      }
    }

    $data = json_encode(array(
      'relativeUrlRoot' => sfContext::getInstance()->request->getRelativeUrlRoot(),
      'frontend' => sfContext::getInstance()->controller->genUrl('@homepage'),
      'drmc' => $drmc
    ));

    return str_ireplace('<head>', '<head>'.javascript_tag(<<<EOF
var Qubit = $data;
EOF
    ), $content);
  }

  /**
   * @see sfApplicationConfiguration
   */
  public function configure()
  {
    $this->dispatcher->connect('response.filter_content', array($this, 'responseFilterContent'));

    $this->dispatcher->connect('access_log.view', array('QubitAccessLogObserver', 'view'));
  }

  /**
   * @see sfApplicationConfiguration
   */
  public function initialize()
  {
    if (false !== $readOnly = getenv('ATOM_READ_ONLY'))
    {
      sfConfig::set('app_read_only', filter_var($readOnly, FILTER_VALIDATE_BOOLEAN));
    }

    // The bootstrapDrmc function needs the environment to be configured
    $this->bootstrapDrmc();
  }

  /**
   * @see sfApplicationConfiguration
   */
  public function getControllerDirs($moduleName)
  {
    if (!isset($this->cache['getControllerDirs'][$moduleName]))
    {
      $this->cache['getControllerDirs'][$moduleName] = array();

      // HACK Currently plugins only override application templates, not the
      // other way around
      foreach ($this->getPluginSubPaths('/modules/'.$moduleName.'/actions') as $dir)
      {
        $this->cache['getControllerDirs'][$moduleName][$dir] = false; // plugins
      }

      $this->cache['getControllerDirs'][$moduleName][sfConfig::get('sf_app_module_dir').'/'.$moduleName.'/actions'] = false; // application
    }

    return $this->cache['getControllerDirs'][$moduleName];
  }

  /**
   * @see sfApplicationConfiguration
   */
  public function getDecoratorDirs()
  {
    $dirs = sfConfig::get('sf_decorator_dirs');
    $dirs[] = sfConfig::get('sf_app_template_dir');

    return $dirs;
  }

  /**
   * @see sfApplicationConfiguration
   */
  public function getTemplateDirs($moduleName)
  {
    // HACK Currently plugins only override application templates, not the
    // other way around
    $dirs = $this->getPluginSubPaths('/modules/'.$moduleName.'/templates');
    $dirs[] = sfConfig::get('sf_app_module_dir').'/'.$moduleName.'/templates';

    $dirs = array_merge($dirs, $this->getDecoratorDirs());

    return $dirs;
  }

  /**
   * @see sfProjectConfiguration
   */
  public function setRootDir($path)
  {
    parent::setRootDir($path);

    $this->setWebDir($path);
  }

  protected function bootstrapDrmc()
  {
    // Load Storage Service and TMS configuration from environment or defaults
    $config = array();
    $envVars = array(
      'ARCHIVEMATICA_SS_HOST' => '127.0.0.1',
      'ARCHIVEMATICA_SS_PORT' => '8000',
      'ARCHIVEMATICA_SS_PIPELINE_UUID' => false,
      'ARCHIVEMATICA_SS_USER' => false,
      'ARCHIVEMATICA_SS_API_KEY' => false,
      'ATOM_DRMC_TMS_URL' => false,
      'ATOM_DRMC_TMS_VERSION' => '1',
    );

    foreach ($envVars as $var => $default)
    {
      $value = getenv($var);

      if (!$value && !$default)
      {
        throw new sfException($var . ' not configured', 500);
      }

      $config[$var] = ($value) ? $value : $default;
    }

    sfConfig::set('app_drmc_ss_host', $config['ARCHIVEMATICA_SS_HOST']);
    sfConfig::set('app_drmc_ss_port', $config['ARCHIVEMATICA_SS_PORT']);
    sfConfig::set('app_drmc_ss_pipeline_uuid', $config['ARCHIVEMATICA_SS_PIPELINE_UUID']);
    sfConfig::set('app_drmc_ss_user', $config['ARCHIVEMATICA_SS_USER']);
    sfConfig::set('app_drmc_ss_api_key', $config['ARCHIVEMATICA_SS_API_KEY']);
    sfConfig::set('app_drmc_tms_url', $config['ATOM_DRMC_TMS_URL']);

    // This are not real TMS API versions, just a way to differentiate
    // between the two current implementations.
    $allowedTmsVersions = array('1', '2');
    $selectedTmsVersion = (string)$config['ATOM_DRMC_TMS_VERSION'];
    if (!in_array($selectedTmsVersion, $allowedTmsVersions))
    {
      throw new sfException('Unknown ATOM_DRMC_TMS_VERSION: ' . $selectedTmsVersion, 500);
    }

    // Form fetch TMS class name from TMS version
    sfConfig::set('app_drmc_tms_class', 'arFetchTmsV' . $selectedTmsVersion);
  }
}
