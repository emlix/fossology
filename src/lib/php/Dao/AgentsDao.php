<?php
/*
Copyright (C) 2008-2012 Hewlett-Packard Development Company, L.P.
Copyright (C) 2014, Siemens AG
Author: Johannes Najjar

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\Dao;

use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Util\Object;
use Monolog\Logger;

/**
 * Class AgentsDao
 * @package Fossology\Lib\Dao
 */
class AgentsDao extends Object
{
  /** @var DbManager */
  private $dbManager;
  
  /**
   * @param DbManager $dbManager
   */
  function __construct(DbManager $dbManager)
  {
    $this->dbManager = $dbManager;
    $this->logger = new Logger(self::className());
  }

  /**
   * @brief
   *  The purpose of this function is to return an array of
   *  _ars records for an agent so that the latest agent_pk(s)
   *  can be determined.
   *
   *  This is for _ars tables only, for example, nomos_ars and bucket_ars.
   *  The _ars tables have a standard format but the specific agent ars table
   *  may have additional fields.
   *
   * @param string $tableName - name of the ars table (e.g. nomos_ars)
   * @param int $upload_pk
   * @param int $limit - limit number of rows returned.  0=No limit, default=1
   * @param int $agent_fk - ARS table agent_fk, optional
   *
   * @return mixed
   * assoc array of _ars records.
   *         or FALSE on error, or no rows
   */
  private function agentARSList($tableName, $upload_pk, $limit = 1, $agent_fk = 0, $agentSuccess = TRUE)
  {
    //based on common-agents.php AgentARSList
    if (!DB_TableExists($tableName)) return false;

    $arguments = array($upload_pk);
    $statementName = __METHOD__ . $tableName;
    
    $agentCond = "";
    if ($agent_fk)
    {
      $arguments[] = $agent_fk;
      $counter = count($arguments);
      $agentCond = " and agent_fk=\$$counter";
      $statementName .= ".agent";
    }

    $limitClause = "";
    if ($limit > 0)
    {
      $arguments[] = $limit;
      $counter = count($arguments);
      $limitClause = " limit \$$counter";
      $statementName .= ".lim";
    }

    $successClause = "";
    if ($agentSuccess)
    {
      $successClause = " and ars_success ";
      $statementName .= ".suc";
    }

    $this->dbManager->prepare($statementName,
        "SELECT * FROM $tableName, agent
           WHERE agent_pk=agent_fk and upload_fk=$1 and agent_enabled=true
           $successClause $agentCond
           order by agent_ts desc $limitClause");

    $result = $this->dbManager->execute($statementName, $arguments);
    $resultArray = $this->dbManager->fetchAll($result);
    $this->dbManager->freeResult($result);
    return $resultArray;
  }


  /**
   * @brief Returns the list of running or failed agent_pk s. Before latest successful run
   * @param $upload_pk
   * @param $arsTableName
   * @return array  - list of running agent pks
   */
  public function RunningAgentpks($upload_pk, $arsTableName)
  {
    $listOfAllJobs = $this->agentARSList($arsTableName, $upload_pk, 0, 0, FALSE);

    $listOfRunningAgents = array();

    if ($listOfAllJobs !== false)
    {
      foreach ($listOfAllJobs as $job)
      {
        if ($job ['ars_success'] === $this->dbManager->booleanToDb(true) )
        {
          break;
        }
        $listOfRunningAgents[] = $job['agent_fk'];
      }
    }
    return $listOfRunningAgents;
  }

  public function getLatestAgentResultForUpload($uploadId, $agentNames)
  {
    $agentLatestMap = array();
    foreach ($agentNames as $agentName)
    {
      $sql = "
SELECT
  agent_pk,
  ars_success,
  ars_endtime,
  agent_name
FROM " . $agentName . "_ars ARS
INNER JOIN agent A ON ARS.agent_fk = A.agent_pk
WHERE upload_fk=$1
  AND A.agent_name = $2
ORDER BY agent_fk DESC";

      $statementName = __METHOD__ . ".$agentName";
      $this->dbManager->prepare($statementName, $sql);
      $res = $this->dbManager->execute($statementName, array($uploadId, $agentName));

      while ($row = $this->dbManager->fetchArray($res))
      {
        if ($row['ars_success'])
        {
          $agentLatestMap[$agentName] = intval($row['agent_pk']);
          break;
        }
      }
      $this->dbManager->freeResult($res);
    }
    return $agentLatestMap;
  }

} 