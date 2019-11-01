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

 /**
  * This class is just a wrapper around the multiple arFetchTmsV* classes.
  * It creates an instance of those classes based on the configuration,
  * making it transparent in the controllers and tasks that use them.
  *
  * @package    symfony
  * @subpackage tms
  */
class arFetchTms extends arFetchTmsBase
{
  private $instance;

  public function __construct()
  {
    $fetchTmsClass = sfConfig::get('app_drmc_tms_class');
    $this->instance = new $fetchTmsClass;
  }

  public function getLastModifiedCheckDate($tmsObjectId)
  {
    return $this->instance->getLastModifiedCheckDate($tmsObjectId);
  }

  public function processArtwork($artwork)
  {
    return $this->instance->processArtwork($artwork);
  }
}
