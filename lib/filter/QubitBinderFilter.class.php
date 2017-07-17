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

class QubitBinderFilter extends sfFilter
{
  public function execute($filterChain)
  {
    $cache = QubitCache::getInstance();
    $cacheKey = 'drmc_config';

    // Hit the cache if config_drmc is available
    if (isset($cache) && $cache->has($cacheKey))
    {
      $drmcConfig = unserialize($cache->get($cacheKey));
    }
    else
    {
      $drmcConfig = array();

      $drmcConfig = arDrmcBootstrapTask::getDrmcConfigArray();

      // Cache
      if (isset($cache))
      {
        $cache->set($cacheKey, serialize($drmcConfig));
      }
    }

    // Dump $drmcConfig in sfConfig
    sfConfig::add($drmcConfig);

    // Execute next filter
    $filterChain->execute();
  }
}
