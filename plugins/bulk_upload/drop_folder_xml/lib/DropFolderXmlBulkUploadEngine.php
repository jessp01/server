<?php
/**
 * @package Scheduler
 * @subpackage Bulk-Upload
 */
class DropFolderXmlBulkUploadEngine extends BulkUploadEngineXml
{
//	TODO - overwrite the ingestion.xsd with version that includes dropFolderFileContentResource
//	/**
//	 * 
//	 * The engine xsd file path
//	 * @var string
//	 */
//	private $xsdFilePath = "/../xml/ingestion.xsd";
	
	
	/* (non-PHPdoc)
	 * @see BulkUploadEngineXml::getResourceInstance()
	 */
	protected function getResourceInstance(SimpleXMLElement $elementToSearchIn)
	{
		if(isset($elementToSearchIn->dropFolderFileContentResource))
		{
			$resource = new KalturaDropFolderFileResource();
			$resource->dropFolderFileId = $elementToSearchIn->dropFolderFileContentResource->dropFolderFileId;
			
			return $resource;
		}
		
		return parent::getResourceInstance($elementToSearchIn);
	}
	
	/* (non-PHPdoc)
	 * @see BulkUploadEngineXml::getResourceInstance()
	 */
	protected function validateResource(KalturaResource $resource, SimpleXMLElement $elementToSearchIn)
	{
		if($resource instanceof KalturaDropFolderFileResource)
		{
			// TODO throw KalturaBulkUploadXmlException in not valid
		}
		
		return parent::validateResource($resource, $elementToSearchIn);
	}
}