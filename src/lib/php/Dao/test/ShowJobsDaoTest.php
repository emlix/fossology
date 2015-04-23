<?php
/*
Copyright (C) 2015, Siemens AG
Author: Johannes Najjar, anupam.ghosh@siemens.com, Shaheem Azmal 

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
use Fossology\Lib\Test\TestPgDb;
use Mockery as M;

class ShowJobsDaoTest extends \PHPUnit_Framework_TestCase
{
  /** @var TestPgDb */
  private $testDb;
  /** @var DbManager */
  private $dbManager;
  /** @var UploadDao */
  private $uploadDao;
  /** @vars ShowJobsDao */
  private $showJobsDao;
  private $job_pks = array(2,1);

  public function setUp()
  {
    $this->testDb = new TestPgDb();
    $this->dbManager = &$this->testDb->getDbManager();

    $this->testDb->createPlainTables(
        array(
            'upload',
            'uploadtree',
            'uploadtree_a',
            'job',
            'perm_upload',
            'jobqueue',
            'jobdepends',
        ));

    $uploadArray = array(array('upload_pk'=>1, 'uploadtree_tablename'=>'uploadtree'),
        array('upload_pk'=>2, 'uploadtree_tablename'=>'uploadtree_a'));
    foreach ($uploadArray as $uploadEntry)
    {
      $this->dbManager->insertTableRow('upload', $uploadEntry);
    }

    $this->dbManager->prepare($stmt = 'insert.job',
        "INSERT INTO job (job_pk, job_queued, job_name, job_upload_fk, job_user_fk) VALUES ($1, $2, $3, $4, $5)");
    $jobArray = array(array(1, "2015-04-21 18:29:19.16051+05:30", "FCKeditor_2.6.4.zip", 1,2 ),
                      array(2,"2015-04-21 20:29:19.16051+05:30", "zlib_1.2.8.zip", 2,2));
    foreach ($jobArray as $uploadEntry)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $uploadEntry));
    }

    $logger = M::mock('Monolog\Logger');
    $logger->shouldReceive('debug');
    $this->uploadDao = new UploadDao($this->dbManager, $logger);
    $this->showJobsDao = new ShowJobsDao($this->dbManager, $this->uploadDao);
    
    $this->assertCountBefore = \Hamcrest\MatcherAssert::getCount();
  }

  public function tearDown()
  {
    $this->addToAssertionCount(\Hamcrest\MatcherAssert::getCount()-$this->assertCountBefore);
    $this->testDb = null;
    $this->dbManager = null;
  }
  


  public function testUploads2Jobs()
  {
    $jobs = array(3=>2, 4=>3, 5=>5, 6=>8%6, 7=>13%6, 8=>21%6);
    foreach ($jobs as $jobId => $jobUpload) {
      $this->dbManager->insertTableRow('job', array('job_pk' => $jobId, 'job_upload_fk' => $jobUpload));
    }
    $uploadDao = M::mock('Fossology\Lib\Dao\UploadDao');
    $showJobDao = new ShowJobsDao($this->dbManager,$uploadDao);
    $jobsWithoutUpload = $showJobDao->uploads2Jobs(array());
    assertThat($jobsWithoutUpload, is(emptyArray()));
    $jobsWithUploadIdOne = $showJobDao->uploads2Jobs(array(1));
    assertThat($jobsWithUploadIdOne, equalTo(array(1,7)));
    $jobsAtAll = $showJobDao->uploads2Jobs(array(1,2,3,4,5));
    assertThat($jobsAtAll, equalTo(array(1,7, 2,3,6, 4,8, 5)));
    $jobsWithUploadFour = $showJobDao->uploads2Jobs(array(4));
    assertThat($jobsWithUploadFour, is(emptyArray()));
  }
  
  public function testUploads2JobsPaged()
  {
    $jobs = array_combine(range(3,13),range(3,13));
    foreach ($jobs as $jobId => $jobUpload) {
      $this->dbManager->insertTableRow('job', array('job_pk' => $jobId, 'job_upload_fk' => $jobUpload));
    }
    $uploadDao = M::mock('Fossology\Lib\Dao\UploadDao');
    $showJobDao = new ShowJobsDao($this->dbManager,$uploadDao);
    
    $jobsPage1 = $showJobDao->uploads2Jobs(range(1,17),0);
    assertThat($jobsPage1, arrayWithSize(10));
    $jobsPage2 = $showJobDao->uploads2Jobs(array_combine(range(10,16),range(11,17)),1);
    assertThat($jobsPage2, arrayWithSize(3));
    $jobsPage3 = $showJobDao->uploads2Jobs(array(),2);
    assertThat($jobsPage3, arrayWithSize(0));
  }
  

  public function testgetJobName()
  {
    $testJobName = $this->showJobsDao->getJobName(1);
    assertThat($testJobName, equalTo("FCKeditor_2.6.4.zip"));

    $testJobNameIfNothingQueued = $this->showJobsDao->getJobName($uploadId = 3);
    assertThat($testJobNameIfNothingQueued, equalTo($uploadId));
  }
  
  public function testMyJobs()
  {
    $this->dbManager->prepare($stmt = 'insert.perm_upload',
      "INSERT INTO perm_upload (perm_upload_pk, perm, upload_fk, group_fk) VALUES ($1, $2, $3, $4)");
    $uploadArrayPerm = array(array(1, 10, 1,2 ),
                             array(2,10, 2,2));
    foreach ($uploadArrayPerm as $uploadEntry)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $uploadEntry));
    }

    $testMyJobs = $this->showJobsDao->myJobs($allusers=1);
    assertThat($testMyJobs,equalTo($this->job_pks));
  }
  
  public function testgetNumItemsPerSec()
  {
    $numSecs = 30;
    $testFilesPerSec = $this->showJobsDao->getNumItemsPerSec(5*$numSecs, $numSecs);
    assertThat($testFilesPerSec,is(greaterThan(1)));

    $testFilesPerSec = $this->showJobsDao->getNumItemsPerSec(0.9*$numSecs, $numSecs);
    assertThat($testFilesPerSec,is(lessThanOrEqualTo(1)));
  }

  public function testGetJobInfo()
  {
    $this->dbManager->prepare($stmt = 'insert.jobqueue',
       "INSERT INTO jobqueue (jq_pk, jq_job_fk, jq_type, jq_args, jq_starttime, jq_endtime, jq_endtext, jq_end_bits, jq_schedinfo, jq_itemsprocessed)"
     . "VALUES ($1, $2, $3, $4,$5, $6,$7,$8,$9,$10)");
    
    $nowTime = time();
    $diffTime = 2345;
    $nomosTime = date('Y-m-d H:i:sO',$nowTime-$diffTime);
    $uploadArrayQue = array(array(8, $jobId=1, "nomos", 1,$nomosTime,null ,"Started", 0,"localhost.5963", $itemNomos=147),
                           array(1, $jobId, "ununpack", 1, "2015-04-21 18:29:19.23825+05:30", "2015-04-21 18:29:26.396562+05:30", "Completed",1,null,$itemCount=646 ));
    foreach ($uploadArrayQue as $uploadEntry)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $uploadEntry));
    }
    
    $this->dbManager->prepare($stmt = 'insert.uploadtree_a',
            "INSERT INTO uploadtree_a (uploadtree_pk, parent, upload_fk, pfile_fk, ufile_mode, lft, rgt, ufile_name)"
         . "VALUES ($1, $2, $3, $4,$5, $6, $7, $8)");
    $uploadTreeArray = array(array(123, 121, 1, 103, 32768, 542, 543, "fckeditorcode_ie.js"),
                             array(121,120, 1, 0, 536888320, 537, 544, "js"),
                             array(715,651, 2,607 ,33188 ,534 ,535 ,"zconf.h.cmakein"),
                             array(915, 651, 2, 606 ,33188 ,532 ,533 ,"zconf.h"),
                          );
    foreach ($uploadTreeArray as $uploadEntry)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $uploadEntry));
    }

    $this->dbManager->prepare($stmt = 'insert.jobdepends',
        "INSERT INTO jobdepends (jdep_jq_fk, jdep_jq_depends_fk) VALUES ($1, $2 )");
    $jobDependsArray = array(array(2,1),
                             array(3,2),
                             array(4,2),
                             array(5,2),
                             array(6,2),
                             array(7,2),
                             array(8,2),
                          );
    foreach ($jobDependsArray as $uploadEntry)
    {
      $this->dbManager->freeResult($this->dbManager->execute($stmt, $uploadEntry));
    }

    $testMyJobInfo = $this->showJobsDao->getJobInfo($this->job_pks);
    assertThat($testMyJobInfo,hasKey($jobId));

    $testFilesPerSec = 0.23;
    $formattedEstimatedTime = $this->showJobsDao->getEstimatedTime($job_pk=1, $jq_Type="nomos", $testFilesPerSec);
    assertThat($formattedEstimatedTime, matchesPattern ('/\\d+:\\d{2}:\\d{2}/'));
    $hourMinSec = explode(':', $formattedEstimatedTime);
    assertThat($hourMinSec[0]*3600+$hourMinSec[1]*60+$hourMinSec[2],
            is(closeTo(($itemCount-$itemNomos)/$testFilesPerSec,$delta=0.5)));
    
    $testGetEstimatedTime = $this->showJobsDao->getEstimatedTime($job_pk=1, $jq_Type, 0);
    assertThat($testGetEstimatedTime, matchesPattern ('/\\d+:\\d{2}:\\d{2}/'));
    $hourMinSec = explode(':', $testGetEstimatedTime);
    assertThat($hourMinSec[0]*3600+$hourMinSec[1]*60+$hourMinSec[2],
            is(closeTo(($itemCount-$itemNomos)/$itemNomos*$diffTime,$delta)));
    
    $fewFilesPerSec = 0.01;
    $formattedLongTime = $this->showJobsDao->getEstimatedTime($job_pk=1, $jq_Type="nomos", $fewFilesPerSec);
    assertThat($formattedLongTime, matchesPattern ('/\\d+:\\d{2}:\\d{2}/'));
    $hourMinSec = explode(':', $formattedEstimatedTime);
    assertThat($hourMinSec[0]*3600+$hourMinSec[1]*60+$hourMinSec[2],
            is(closeTo(($itemCount-$itemNomos)/$testFilesPerSec,$delta)));
  }
}
