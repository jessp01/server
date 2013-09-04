<?php
/**
 * This engine handles the basiC use-cases of drop folders- local, and remote FTP, SFTP.
 */
class KDropFolderFileTransferEngine extends KDropFolderEngine
{
	const IGNORE_PATTERNS_DEFAULT_VALUE  = '*.cache,*.aspx';
	
	/**
	 * @var kFileTransferMgr
	 */	
	protected $fileTransferMgr;

	
	public function watchFolder (KalturaDropFolder $folder)
	{
		$this->dropFolder = $folder;
		$this->getFileTransferManager();
		KalturaLog::debug('Watching folder ['.$this->dropFolder->id.']');
						    										
		$physicalFiles = $this->getDropFolderFilesFromPhysicalFolder();
		if(count($physicalFiles) > 0)
			$dropFolderFilesMap = $this->loadDropFolderFiles();
		else 
			$dropFolderFilesMap = array();

		$maxModificationTime = 0;
		foreach ($physicalFiles as $physicalFile) 
		{
			/* @var $physicalFile FileObject */	
			$physicalFileName = $physicalFile->filename;
			if ($this->dropFolder->incremental && $physicalFile->modificationTime < $this->dropFolder->lastFileTimestamp)
				continue;
			
			if($this->validatePhysicalFile($physicalFileName))
			{
				$maxModificationTime = ($physicalFile->modificationTime > $maxModificationTime) ? $physicalFile->modificationTime : $maxModificationTime;
				KalturaLog::debug('Watch file ['.$physicalFileName.']');
				if(!array_key_exists($physicalFileName, $dropFolderFilesMap))
				{
					try 
					{
						$lastModificationTime = $physicalFile->modificationTime;
						$fileSize = $physicalFile->fileSize;
						$this->handleFileAdded($physicalFileName, $fileSize, $lastModificationTime);
					}
					catch (Exception $e)
					{
						KalturaLog::err("Error handling drop folder file [$physicalFileName] " . $e->getMessage());
					}											
				}
				else //drop folder file entry found
				{
					$dropFolderFile = $dropFolderFilesMap[$physicalFileName];
					//if file exist in the folder remove it from the map
					//all the files that are left in a map will be marked as PURGED					
					unset($dropFolderFilesMap[$physicalFileName]);
					$this->handleExistingDropFolderFile($dropFolderFile);
				}					
			}					
		}
		foreach ($dropFolderFilesMap as $dropFolderFile) 
		{
			$this->handleFilePurged($dropFolderFile->id);
		}
		
		if ($this->dropFolder->incremental)
		{
			$updateDropFolder = new KalturaDropFolder();
			$updateDropFolder->lastFileTimestamp = $maxModificationTime;
			$this->dropFolderPlugin->dropFolder->update($this->dropFolder->id, $updateDropFolder);
		}
	}
	
	protected function fileExists ()
	{
		return $this->fileTransferMgr->fileExists($this->dropFolder->path);
	}
	
	public function handleExistingDropFolderFile (KalturaDropFolderFile $dropFolderFile)
	{
		KalturaLog::debug('Handling existing drop folder file with id ['.$dropFolderFile->id.']');
		try 
		{
			$fullPath = $this->dropFolder->path.'/'.$dropFolderFile->fileName;
			$lastModificationTime = $this->fileTransferMgr->modificationTime($fullPath);
			$fileSize = $this->fileTransferMgr->fileSize($fullPath);
		}
		catch (Exception $e)
		{
			KalturaLog::err('Failed to get modification time and file size for file ['.$fullPath.']');
			$this->handleFileError($dropFolderFile->id, KalturaDropFolderFileStatus::ERROR_HANDLING, KalturaDropFolderFileErrorCode::ERROR_READING_FILE, 
															DropFolderPlugin::ERROR_READING_FILE_MESSAGE. '['.$fullPath.']', $e);	
			return false;		
		}				 
				
		if($dropFolderFile->status == KalturaDropFolderFileStatus::UPLOADING)
		{
			$this->handleUploadingDropFolderFile($dropFolderFile, $fileSize, $lastModificationTime);
		}
		else
		{
			KalturaLog::debug('Last modification time ['.$lastModificationTime.'] known last modification time ['.$dropFolderFile->lastModificationTime.']');
			$isLastModificationTimeUpdated = $dropFolderFile->lastModificationTime && $dropFolderFile->lastModificationTime != '' && ($lastModificationTime > $dropFolderFile->lastModificationTime);
			
			if($isLastModificationTimeUpdated) //file is replaced, add new entry
		 	{
		 		$this->handleFileAdded($dropFolderFile->fileName, $fileSize, $lastModificationTime);
		 	}
		 	else
		 	{
		 		$deleteTime = $dropFolderFile->updatedAt + $this->dropFolderfolder->autoFileDeleteDays*86400;
		 		if(($dropFolderFile->status == KalturaDropFolderFileStatus::HANDLED && $this->dropFolder->fileDeletePolicy == KalturaDropFolderFileDeletePolicy::AUTO_DELETE && time() > $deleteTime) ||
		 			$dropFolderFile->status == KalturaDropFolderFileStatus::DELETED)
		 		{
		 			$this->purgeFile($dropFolderFile);
		 		}
		 	}
		}
	}
	
	protected function handleUploadingDropFolderFile (KalturaDropFolderFile $dropFolderFile, $currentFileSize, $lastModificationTime)
	{
		if (!$currentFileSize) 
		{
			$this->handleFileError($dropFolderFile->id, KalturaDropFolderFileStatus::ERROR_HANDLING, KalturaDropFolderFileErrorCode::ERROR_READING_FILE, 
															DropFolderPlugin::ERROR_READING_FILE_MESSAGE.'['.$this->dropFolder->path.'/'.$dropFolderFile->fileName);
		}		
		else if ($currentFileSize != $dropFolderFile->fileSize)
		{
			$this->handleFileUploading($dropFolderFile->id, $currentFileSize, $lastModificationTime);
		}
		else // file sizes are equal
		{
			$time = time();
			$fileSizeLastSetAt = $dropFolder->fileSizeCheckInterval + $dropFolderFile->fileSizeLastSetAt;
			
			KalturaLog::debug("time [$time] fileSizeLastSetAt [$fileSizeLastSetAt]");
			
			// check if fileSizeCheckInterval time has passed since the last file size update	
			if ($time > $fileSizeLastSetAt)
			{
				$this->handleFileUploaded($dropFolderFile->id, $lastModificationTime);
			}
		}
	}
	
	protected function handleFileAdded ($fileName, $fileSize, $lastModificationTime)
	{
		KalturaLog::debug('Add drop folder file ['.$fileName.'] last modification time ['.$lastModificationTime.'] file size ['.$fileSize.']');
		try 
		{
			$newDropFolderFile = new KalturaDropFolderFile();
	    	$newDropFolderFile->dropFolderId = $this->dropFolder->id;
	    	$newDropFolderFile->fileName = $fileName;
	    	$newDropFolderFile->fileSize = $fileSize;
	    	$newDropFolderFile->lastModificationTime = $lastModificationTime; 
	    	$newDropFolderFile->uploadStartDetectedAt = time();
			$dropFolderFile = $this->dropFolderFileService->add($newDropFolderFile);
			return $dropFolderFile;
		}
		catch(Exception $e)
		{
			KalturaLog::err('Cannot add new drop folder file with name ['.$fileName.'] - '.$e->getMessage());
			return null;
		}
	}
	
	protected function validatePhysicalFile ($physicalFile)
	{
		KalturaLog::log('Validating physical file ['.$physicalFile.']');
		
		$ignorePatterns = $this->dropFolder->ignoreFileNamePatterns;	
		if($ignorePatterns)
			$ignorePatterns = self::IGNORE_PATTERNS_DEFAULT_VALUE.','.$ignorePatterns;
		else
			$ignorePatterns = self::IGNORE_PATTERNS_DEFAULT_VALUE;			
		$ignorePatterns = array_map('trim', explode(',', $ignorePatterns));
		
		$isValid = true;
		try 
		{
			$fullPath = $this->dropFolder->path.'/'.$physicalFile;
			if (empty($physicalFile) || $physicalFile === '.' || $physicalFile === '..')
			{
				KalturaLog::err("File name is not set");
				$isValid = false;
			}
			else if(!$fullPath || !$this->fileTransferMgr->fileExists($fullPath))
			{
				KalturaLog::err("Cannot access physical file in path [$fullPath]");
				$isValid = false;				
			}
			else
			{
				foreach ($ignorePatterns as $ignorePattern)
				{
					if (!is_null($ignorePattern) && ($ignorePattern != '') && fnmatch($ignorePattern, $physicalFile)) 
					{
						KalturaLog::err("Ignoring file [$physicalFile] matching ignore pattern [$ignorePattern]");
						$isValid = false;
					}
				}
			}
		}
		catch(Exception $e)
		{
			KalturaLog::err("Failure validating physical file [$physicalFile] - ". $e->getMessage());
			$isValid = false;
		}
		return $isValid;
	}
	
	/** 
     * Init a kFileTransferManager acccording to folder type and login to the server
     * @throws Exception
     * 
     * @return kFileTransferMgr
     */
	private function getFileTransferManager()
	{
		$engineOptions = isset(KBatchBase::$taskConfig->engineOptions) ? KBatchBase::$taskConfig->engineOptions->toArray() : array();
	    $this->fileTransferMgr = kFileTransferMgr::getInstance(self::getFileTransferMgrType($this->dropFolder->type), $engineOptions);
	    
	    $host =null; $username=null; $password=null; $port=null;
	    $privateKey = null; $publicKey = null;
	    
	    if($this->dropFolder instanceof KalturaRemoteDropFolder)
	    {
	   		$host = $this->dropFolder->host;
	    	$port = $this->dropFolder->port;
	    	$username = $this->dropFolder->username;
	    	$password = $this->dropFolder->password;
	    }  
	    if($this->dropFolder instanceof KalturaSshDropFolder)
	    {
	    	$privateKey = $this->dropFolder->privateKey;
	    	$publicKey = $this->dropFolder->publicKey;
	    	$passPhrase = $this->dropFolder->passPhrase;  	    	
	    }

        // login to server
        if ($privateKey || $publicKey) 
        {
	       	$privateKeyFile = self::getTempFileWithContent($privateKey, 'privateKey');
        	$publicKeyFile = self::getTempFileWithContent($publicKey, 'publicKey');
        	$this->fileTransferMgr->loginPubKey($host, $username, $publicKeyFile, $privateKeyFile, $passPhrase, $port);        	
        }
        else 
        {
        	$this->fileTransferMgr->login($host, $username, $password, $port);        	
        }
		return $this->fileTransferMgr;		
	}

		/**
	 * This mapping is required since the Enum values of the drop folder and file transfer manager are not the same
	 * @param int $dropFolderType
	 */
	private static function getFileTransferMgrType($dropFolderType)
	{
		switch ($dropFolderType)
		{
			case KalturaDropFolderType::LOCAL:
				return kFileTransferMgrType::LOCAL;
			case KalturaDropFolderType::FTP:
				return kFileTransferMgrType::FTP;
			case KalturaDropFolderType::SCP:
				return kFileTransferMgrType::SCP;
			case KalturaDropFolderType::SFTP:
				return kFileTransferMgrType::SFTP;
			case KalturaDropFolderType::S3:
				return kFileTransferMgrType::S3;
			default:
				return $dropFolderType;				
		}
		
	}
	
	/**
	 * Lazy saving of file content to a temporary path, the file will exist in this location until the temp files are purged
	 * @param string $fileContent
	 * @param string $prefix
	 * @return string path to temporary file location
	 */
	private static function getTempFileWithContent($fileContent, $prefix = '') 
	{
		if(!$fileContent)
			return null;
		$tempDirectory = sys_get_temp_dir();
		$fileLocation = tempnam($tempDirectory, $prefix);		
		file_put_contents($fileLocation, $fileContent);
		return $fileLocation;
	}
	
	/**
	 * Update uploading details
	 * @param int $dropFolderFileId
	 * @param int $fileSize
	 * @param int $lastModificationTime
	 * @param int $uploadStartDetectedAt
	 */
	public function handleFileUploading($dropFolderFileId, $fileSize, $lastModificationTime, $uploadStartDetectedAt = null)
	{
		KalturaLog::debug('Handling drop folder file uploading id ['.$dropFolderFileId.'] fileSize ['.$fileSize.'] last modification time ['.$lastModificationTime.']');
		try 
		{
			$updateDropFolderFile = new KalturaDropFolderFile();
			$updateDropFolderFile->fileSize = $fileSize;
			$updateDropFolderFile->lastModificationTime = $lastModificationTime;
			if($uploadStartDetectedAt)
			{
				$updateDropFolderFile->uploadStartDetectedAt = $uploadStartDetectedAt;
			}
			return $this->dropFolderFileService->update($dropFolderFileId, $updateDropFolderFile);
		}
		catch (Exception $e) 
		{
			$this->handleFileError($dropFolderFileId, KalturaDropFolderFileStatus::ERROR_HANDLING, KalturaDropFolderFileErrorCode::ERROR_UPDATE_FILE, 
									DropFolderPlugin::ERROR_UPDATE_FILE_MESSAGE, $e);
			return null;
		}						
	}
	
	/**
	 * Update upload details and set file status to PENDING
	 * @param int $dropFolderFileId
	 * @param int $lastModificationTime
	 */
	protected function handleFileUploaded($dropFolderFileId, $lastModificationTime)
	{
		KalturaLog::debug('Handling drop folder file uploaded id ['.$dropFolderFileId.'] last modification time ['.$lastModificationTime.']');
		try 
		{
			$updateDropFolderFile = new KalturaDropFolderFile();
			$updateDropFolderFile->lastModificationTime = $lastModificationTime;
			$updateDropFolderFile->uploadEndDetectedAt = time();
			$this->dropFolderFileService->update($dropFolderFileId, $updateDropFolderFile);
			return $this->dropFolderFileService->updateStatus($dropFolderFileId, KalturaDropFolderFileStatus::PENDING);			
		}
		catch(KalturaException $e)
		{
			$this->handleFileError($dropFolderFileId, KalturaDropFolderFileStatus::ERROR_HANDLING, KalturaDropFolderFileErrorCode::ERROR_UPDATE_FILE, 
									DropFolderPlugin::ERROR_UPDATE_FILE_MESSAGE, $e);
			return null;
		}
	}
	
	protected function purgeFile(KalturaDropFolderFile $dropFolderFile)
	{
		$fullPath = $this->dropFolder->path.'/'.$dropFolderFile->fileName;
		// physicaly delete the file
		$delResult = null;
		try 
		{
		    $delResult = $this->fileTransferMgr->delFile($fullPath);
		}
		catch (Exception $e) 
		{
			KalturaLog::err("Error when deleting drop folder file - ".$e->getMessage());
		    $delResult = null;
		}
		if (!$delResult) 
			$this->handleFileError($dropFolderFile->id, KalturaDropFolderFileStatus::ERROR_DELETING, KalturaDropFolderFileErrorCode::ERROR_DELETING_FILE, 
														 DropFolderPlugin::ERROR_DELETING_FILE_MESSAGE. '['.$fullPath.']');
		else
		 	$this->handleFilePurged($dropFolderFile->id);
	}
	
	protected function getDropFolderFilesFromPhysicalFolder()
	{
		KalturaLog::debug('Retrieving physical files list');
		
		if($this->fileTransferMgr->fileExists($this->dropFolder->path))
		{
			$physicalFiles = $this->fileTransferMgr->listFileObjects($this->dropFolder->path);
			if ($physicalFiles) 
			{
				KalturaLog::log('Found ['.count($physicalFiles).'] in the folder');			
			}		
			else
			{
				KalturaLog::info('No physical files found for drop folder id ['.$this->dropFolder->id.'] with path ['.$this->dropFolder->path.']');
				$physicalFiles = array();
			}
		}
		else 
		{
			throw new kFileTransferMgrException('Drop folder path not valid ['.$this->dropFolder->path.']', kFileTransferMgrException::remotePathNotValid);
		}
			
		return $physicalFiles;
	}
	
	public function processFolder (KalturaBatchJob $job, KalturaDropFolderContentProcessorJobData $data)
	{
		KBatchBase::impersonate($job->partnerId);
		
		switch ($data->contentMatchPolicy)
		{
			case KalturaDropFolderContentFileHandlerMatchPolicy::ADD_AS_NEW:
				$this->addAsNewContent($job, $data);
				break;
			
			case KalturaDropFolderContentFileHandlerMatchPolicy::MATCH_EXISTING_OR_KEEP_IN_FOLDER:
				$this->addAsExistingContent($job, $data);
				break;
				
			case KalturaDropFolderContentFileHandlerMatchPolicy::MATCH_EXISTING_OR_ADD_AS_NEW:
				$matchedEntry = $this->isEntryMatch($data);
				if($matchedEntry)
					$this->addAsExistingContent($job, $data, $matchedEntry);
				else
					 $this->addAsNewContent($job, $data);	
				break;			
			default:
				KalturaLog::err('No content match policy is defined for drop folder');
				throw new kApplicativeException(KalturaDropFolderErrorCode::CONTENT_MATCH_POLICY_UNDEFINED, 'No content match policy is defined for drop folder'); 
				break;
		}
		
		KBatchBase::unimpersonate();
	}
	
	private function addAsNewContent(KalturaBatchJob $job, KalturaDropFolderContentProcessorJobData $data)
	{ 		
		$resource = $this->getIngestionResource($job, $data);
		$newEntry = new KalturaBaseEntry();
		$newEntry->conversionProfileId = $data->conversionProfileId;
		$newEntry->name = $data->parsedSlug;
		$newEntry->referenceId = $data->parsedSlug;
			
		$addedEntry = KBatchBase::$kClient->baseEntry->add($newEntry, null);
		$addedEntry = KBatchBase::$kClient->baseEntry->addContent($addedEntry->id, $resource);	
	}

	private function isEntryMatch(KalturaDropFolderContentProcessorJobData $data)
	{
		try 
		{
			$entryFilter = new KalturaBaseEntryFilter();
			$entryFilter->referenceIdEqual = $data->parsedSlug;
			$entryFilter->statusIn = KalturaEntryStatus::IMPORT.','.KalturaEntryStatus::PRECONVERT.','.KalturaEntryStatus::READY.','.KalturaEntryStatus::PENDING.','.KalturaEntryStatus::NO_CONTENT;		
			
			$entryPager = new KalturaFilterPager();
			$entryPager->pageSize = 1;
			$entryPager->pageIndex = 1;
			$entryList = KBatchBase::$kClient->baseEntry->listAction($entryFilter, $entryPager);
			
			if (is_array($entryList->objects) && isset($entryList->objects[0]) ) 
			{
				$result = $entryList->objects[0];
				if ($result->referenceId === $data->parsedSlug) 
					return $result;
			}
			
			return false;			
		}
		catch (Exception $e)
		{
			KalturaLog::err('Failed to get entry by reference id: [$data->parsedSlug] - '. $e->getMessage() );
			return false;
		}
	}
	
	/**
	 * Match the current file to an existing entry and flavor according to the slug regex.
	 * Update the matched entry with the new file and all other relevant files from the drop folder, according to the ingestion profile.
	 *
	 */
	private function addAsExistingContent(KalturaBatchJob $job, KalturaDropFolderContentProcessorJobData $data, $matchedEntry = null)
	{	    
		// check for matching entry and flavor
		if(!$matchedEntry)
		{
			$matchedEntry = $this->isEntryMatch($data);
			if(!$matchedEntry)
			{
				$e = new kTemporaryException('No matching entry found', KalturaDropFolderFileErrorCode::FILE_NO_MATCH);
				if(($job->queueTime + KBatchBase::$taskConfig->params->maxTimeBeforeFail) >= time())	
				{
					$e->setResetJobExecutionAttempts(true);
				}	
				throw $e;		
			}
		}	
		$resource = $this->getIngestionResource($job, $data);
		KBatchBase::$kClient->media->cancelReplace($matchedEntry->id);
		$updatedEntry = KBatchBase::$kClient->baseEntry->updateContent($matchedEntry->id, $resource, $data->conversionProfileId);
	}
	
	/**
	 * Retrieve all the relevant drop folder files according to the list of id's passed on the job data.
	 * Create resource object based on the conversion profile as an input to the ingestion API
	 * @param KalturaBatchJob $job
	 * @param KalturaDropFolderContentProcessorJobData $data
	 */
	private function getIngestionResource(KalturaBatchJob $job, KalturaDropFolderContentProcessorJobData $data)
	{
		$filter = new KalturaDropFolderFileFilter();
		$filter->idIn = $data->dropFolderFileIds;
		$dropFolderFiles = $this->dropFolderFileService->listAction($filter); 
		
		$resource = null;
		if($dropFolderFiles->totalCount == 1 && is_null($dropFolderFiles->objects[0]->parsedFlavor)) //only source is ingested
		{
			$resource = new KalturaDropFolderFileResource();
			$resource->dropFolderFileId = $dropFolderFiles->objects[0]->id;			
		}
		else //ingest all the required flavors
		{			
			$fileToFlavorMap = array();
			foreach ($dropFolderFiles->objects as $dropFolderFile) 
			{
				$fileToFlavorMap[$dropFolderFile->parsedFlavor] = $dropFolderFile->id;			
			}
			
			$assetContainerArray = array();
		
			$assetParamsFilter = new KalturaConversionProfileAssetParamsFilter();
			$assetParamsFilter->conversionProfileIdEqual = $data->conversionProfileId;
			$assetParamsList = KBatchBase::$kClient->conversionProfileAssetParams->listAction($assetParamsFilter);
			foreach ($assetParamsList->objects as $assetParams)
			{
				if(array_key_exists($assetParams->systemName, $fileToFlavorMap))
				{
					$assetContainer = new KalturaAssetParamsResourceContainer();
					$assetContainer->assetParamsId = $assetParams->assetParamsId;
					$assetContainer->resource = new KalturaDropFolderFileResource();
					$assetContainer->resource->dropFolderFileId = $fileToFlavorMap[$assetParams->systemName];
					$assetContainerArray[] = $assetContainer;				
				}			
			}		
			$resource = new KalturaAssetsParamsResourceContainers();
			$resource->resources = $assetContainerArray;
		}
		return $resource;		
	}
}