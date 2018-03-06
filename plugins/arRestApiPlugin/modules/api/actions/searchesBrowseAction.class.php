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

class ApiSearchesBrowseAction extends QubitApiAction
{
  protected function get($request)
  {
    return $this->getResults();
  }

  protected function getResults()
  {
    // Create query objects
    $query = new \Elastica\Query;
    $queryBool = new \Elastica\Query\BoolQuery;
    $queryBool->addMust(new \Elastica\Query\Term(array('typeId' => sfConfig::get('app_drmc_term_search_id'))));

    // Pagination and sorting
    $this->prepareEsPagination($query);
    $this->prepareEsSorting($query, array(
      'name' => 'name.untouched',
      'description' => 'description',
      'createdAt' => 'createdAt',
      'updatedAt' => 'updatedAt',
      'type' => 'scope',
      'user' => 'user.name'));

    // Filter query
    if (isset($this->request->query) && 1 !== preg_match('/^[\s\t\r\n]*$/', $this->request->query))
    {
      $queryFields = array(
        'name.autocomplete',
        'scope',
        'user.name',
        'description'
      );

      $queryText = new \Elastica\Query\QueryString($this->request->query);
      $queryText->setFields($queryFields);

      $queryBool->addMust($queryText);
    }

    // Filter selected aggregations
    $this->filterEsQuery('user', 'user.id', $queryBool);
    $this->filterEsQuery('type', 'scope', $queryBool, 'AND', array('noInteger' => true));

    $this->filterEsRangeQuery('createdFrom', 'createdTo', 'createdAt', $queryBool);
    $this->filterEsRangeQuery('updatedFrom', 'updatedTo', 'updatedAt', $queryBool);

    // Type aggregation labels
    $this->typeLabels = array(
      'aips' => 'AIPs',
      'works' => 'Artwork records',
      'technology-records' => 'Supporting technology records',
      'components' => 'Components',
      'files' => 'Files');

    // Add aggregations to the query
    $query->addAggregation($this->buildEsAgg('Terms', 'type', 'scope'));
    $query->addAggregation($this->buildEsAgg('Terms', 'user', 'user.id'));

    $now = new DateTime();
    $now->setTime(0, 0);

    $dateRanges = array(
      array(
        'from' => null,
        'to' => $now->modify('-1 year')->getTimestamp().'000',
        'key' => 'Older than a year'
      ),
      array(
        'from' => $now->getTimestamp().'000',
        'to' => null,
        'key' => 'From last year'
      ),
      array(
        'from' => $now->modify('+11 months')->getTimestamp().'000',
        'to' => null,
        'key' => 'From last month'
      ),
      array(
        'from' => $now->modify('+1 month')->modify('-7 days')->getTimestamp().'000',
        'to' => null,
        'key' => 'From last week'
      )
    );

    $query->addAggregation($this->buildEsAgg(
      'DateRange',
      'dateCreated',
      'createdAt',
      array('ranges' => $dateRanges)
    ));

    $query->addAggregation($this->buildEsAgg(
      'DateRange',
      'dateUpdated',
      'updatedAt',
      array('ranges' => $dateRanges)
    ));

    // Assign query
    $query->setQuery($queryBool);

    $resultSet = QubitSearch::getInstance()->index->getType('QubitSavedQuery')->search($query);

    $data = array();
    foreach ($resultSet as $hit)
    {
      $doc = $hit->getData();

      $search = array();

      $this->addItemToArray($search, 'id', $hit->getId());
      $this->addItemToArray($search, 'name', $doc['name']);
      $this->addItemToArray($search, 'type', $doc['scope']);
      $this->addItemToArray($search, 'description', $doc['description']);
      $this->addItemToArray($search, 'created_at', arRestApiPluginUtils::convertDate($doc['createdAt']));
      $this->addItemToArray($search, 'updated_at', arRestApiPluginUtils::convertDate($doc['updatedAt']));
      $this->addItemToArray($search, 'slug', $doc['slug']);
      $this->addItemToArray($search, 'criteria', unserialize($doc['params']));
      $this->addItemToArray($search['user'], 'id', $doc['user']['id']);
      $this->addItemToArray($search['user'], 'name', $doc['user']['name']);

      $data['results'][] = $search;
    }

    $aggs = $resultSet->getAggregations();
    $this->formatAggs($aggs);

    $data['aggs'] = $aggs;
    $data['total'] = $resultSet->getTotalHits();
    $data['overview'] = $this->getOverview();

    return $data;
  }

  protected function getOverview()
  {
    $query = new \Elastica\Query;
    $queryBool = new \Elastica\Query\BoolQuery;
    $queryBool->addMust(new \Elastica\Query\Term(array('typeId' => sfConfig::get('app_drmc_term_search_id'))));

    $query->addAggregation($this->buildEsAgg('Terms', 'type', 'scope'));
    $query->setQuery($queryBool);
    $query->setSort(array('createdAt' => 'desc'));
    $query->setSize(1);

    $resultSet = QubitSearch::getInstance()->index->getType('QubitSavedQuery')->search($query);
    $aggs = $resultSet->getAggregations();
    $this->formatAggs($aggs);

    $results = array();

    // Totals by entity
    foreach ($aggs['type']['buckets'] as $bucket)
    {
      $results['counts'][$bucket['label'].' searches'] = $bucket['doc_count'];
    }

    // Total searches
    $results['counts']['Total searches'] = $resultSet->getTotalHits();

    // Last created
    $esResullts = $resultSet->getResults();

    if (count($esResullts) == 1)
    {
      $lastCreated = $esResullts[0]->getData();

      $results['latest']['Last search added']['date'] = arRestApiPluginUtils::convertDate($lastCreated['createdAt']);
      $results['latest']['Last search added']['user'] = $lastCreated['user']['name'];
      $results['latest']['Last search added']['name'] = $lastCreated['name'];
      $results['latest']['Last search added']['slug'] = $lastCreated['slug'];
    }

    // Last updated
    $query = new \Elastica\Query;
    $queryBool = new \Elastica\Query\BoolQuery;
    $queryBool->addMust(new \Elastica\Query\Term(array('typeId' => sfConfig::get('app_drmc_term_search_id'))));

    $query->setQuery($queryBool);
    $query->setSort(array('updatedAt' => 'desc'));
    $query->setSize(1);

    $resultSet = QubitSearch::getInstance()->index->getType('QubitSavedQuery')->search($query);

    $esResullts = $resultSet->getResults();

    if (count($esResullts) == 1)
    {
      $lastUpdated = $esResullts[0]->getData();

      $results['latest']['Last search modified']['date'] = arRestApiPluginUtils::convertDate($lastUpdated['createdAt']);
      $results['latest']['Last search modified']['user'] = $lastUpdated['user']['name'];
      $results['latest']['Last search modified']['name'] = $lastUpdated['name'];
      $results['latest']['Last search modified']['slug'] = $lastCreated['slug'];
    }

    return $results;
  }

  protected function getAggLabel($name, $id)
  {
    switch ($name)
    {
      case 'type':
        return $this->typeLabels[$id];

        break;

      case 'user':
        if (null !== $item = QubitUser::getById($id))
        {
          return $item->getUsername(array('cultureFallback' => true));
        }

        break;
    }
  }
}
