<?php


class ComcastChoiceTemplate extends SoapObject
{				
	public function getType()
	{
		return 'ChoiceTemplate';
	}
	
	protected function getAttributeType($attributeName)
	{
		switch($attributeName)
		{	
			case 'fields':
				return 'ComcastArrayOfChoiceField';
			case 'customFields':
				return 'ComcastArrayOfstring';
			case 'contentCustomFields':
				return 'ComcastArrayOfstring';
			default:
				return parent::getAttributeType($attributeName);
		}
	}
					
	public function __toString()
	{
		return print_r($this, true);	
	}
				
	/**
	 * @var ComcastArrayOfChoiceField
	 **/
	public $fields;
				
	/**
	 * @var ComcastArrayOfstring
	 **/
	public $customFields;
				
	/**
	 * @var ComcastArrayOfstring
	 **/
	public $contentCustomFields;
				
}


