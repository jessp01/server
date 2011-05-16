<?php


class ComcastDRMChallengeState extends SoapObject
{				
	public function getType()
	{
		return 'DRMChallengeState';
	}
	
	protected function getAttributeType($attributeName)
	{
		switch($attributeName)
		{	
			case 'licenseStates':
				return 'ComcastArrayOfDRMLicenseState';
			default:
				return parent::getAttributeType($attributeName);
		}
	}
					
	public function __toString()
	{
		return print_r($this, true);	
	}
				
	/**
	 * @var string
	 **/
	public $individualization;
				
	/**
	 * @var long
	 **/
	public $releaseID;
				
	/**
	 * @var string
	 **/
	public $releasePID;
				
	/**
	 * @var ComcastArrayOfDRMLicenseState
	 **/
	public $licenseStates;
				
}


