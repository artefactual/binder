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

class ApiSummaryArtworkByDateAction extends QubitApiAction
{
  protected function get($request)
  {
    $data = array();

    $data['results'] = $this->getResults();

    return $data;
  }

  protected function getResults()
  {
    // Create query objects
    $query = new \Elastica\Query;
    $queryBool = new \Elastica\Query\Bool;

    // Get all artwork records
    $queryMatch = new \Elastica\Query\Match;
    $queryMatch->setField(
      'levelOfDescriptionId',
      sfConfig::get('app_drmc_lod_artwork_record_id')
    );
    $queryBool->addShould($queryMatch);

    // Assign query
    $query->setQuery($queryBool);

    // We don't need details, just facet results
    $query->setLimit(0);

    // Add facets to the months in which artwork records were collected and created
    $this->facetEsQuery('DateHistogram', 'collection', 'tmsObject.dateCollected', $query, array('interval' => 'year'));
    $this->facetEsQuery('DateHistogram', 'creation', 'createdAt', $query, array('interval' => 'month'));

    // Return empty results if search fails
    try
    {
      $resultSet = QubitSearch::getInstance()->index->getType('QubitInformationObject')->search($query);
    }
    catch (Exception $e)
    {
      return array(
        'creation' => array(),
        'collection' => array()
      );
    }

    $facets = $resultSet->getFacets();

    // Convert timestamps and calculate running total.
    // Add missing intervals: ElasticSearch 0.9 facets
    // don't include intervals without data, it can be solved
    // using aggregations in Elasticsearch 1.x.
    $results = array();
    foreach ($facets as $facetName => $facet)
    {
      $total = 0;

      foreach ($facet['entries'] as $entry)
      {
        // Calculate running total
        $total += $entry['count'];

        // Create result from entry
        $result = array();
        $result['total'] = $total;
        $result['count'] = $entry['count'];

        // Convert millisecond timestamps to years and months
        $timestamp = $entry['time'] / 1000;
        $result['year'] = (integer)substr(date('Y-m-d', $timestamp), 0, 4);

        if ($facetName == 'creation')
        {
          $result['month'] = (integer)substr(date('Y-m-d', $timestamp), 5, 2);

          if (isset($previousResult))
          {
            // Add missing months in between to creation results
            while (($result['year'] === $previousResult['year'] &&
              $result['month'] - $previousResult['month'] > 1) ||
              $result['year'] - $previousResult['year'] > 1 ||
              ($result['year'] - $previousResult['year'] === 1 &&
              $result['month'] - $previousResult['month'] !== -11))
            {
              $missingResult = array();
              $missingResult['total'] = $previousResult['total'];
              $missingResult['count'] = 0;

              if ($previousResult['month'] == 12)
              {
                $missingResult['year'] = $previousResult['year'] + 1;
                $missingResult['month'] = 1;
              }
              else
              {
                $missingResult['year'] = $previousResult['year'];
                $missingResult['month'] = $previousResult['month'] + 1;
              }

              $results[$facetName][] = $missingResult;
              $previousResult = $missingResult;
            }
          }
        }
        else if (isset($previousResult))
        {
          // Add missing years in between to collection results
          while ($result['year'] - $previousResult['year'] > 1)
          {
            $missingResult = array();
            $missingResult['total'] = $previousResult['total'];
            $missingResult['count'] = 0;
            $missingResult['year'] = $previousResult['year'] + 1;

            $results[$facetName][] = $missingResult;
            $previousResult = $missingResult;
          }
        }

        $results[$facetName][] = $result;
        $previousResult = $result;
      }

      $currentYear = (integer)substr(date('Y-m-d'), 0, 4);

      if ($facetName == 'creation')
      {
        $currentMonth = (integer)substr(date('Y-m-d'), 5, 2);

        // Add missing months at the end to creation results
        while ($currentYear !== $previousResult['year'] &&
          $currentMonth !== $previousResult['month'])
        {
          $missingResult = array();
          $missingResult['total'] = $previousResult['total'];
          $missingResult['count'] = 0;

          if ($previousResult['month'] == 12)
          {
            $missingResult['year'] = $previousResult['year'] + 1;
            $missingResult['month'] = 1;
          }
          else
          {
            $missingResult['year'] = $previousResult['year'];
            $missingResult['month'] = $previousResult['month'] + 1;
          }

          $results[$facetName][] = $missingResult;
          $previousResult = $missingResult;
        }
      }
      else
      {
        // Add missing years at the end to collection results
        while ($currentYear - $previousResult['year'] > 0)
        {
          $missingResult = array();
          $missingResult['total'] = $previousResult['total'];
          $missingResult['count'] = 0;
          $missingResult['year'] = $previousResult['year'] + 1;

          $results[$facetName][] = $missingResult;
          $previousResult = $missingResult;
        }
      }
    }

    return $results;
  }
}
