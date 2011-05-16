<?php


class ComcastStorefrontOrderOptions extends SoapObject
{				
	public function getType()
	{
		return 'StorefrontOrderOptions';
	}
	
	protected function getAttributeType($attributeName)
	{
		switch($attributeName)
		{	
			case 'couponCodes':
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
	 * @var ComcastArrayOfstring
	 **/
	public $couponCodes;
				
	/**
	 * @var string
	 **/
	public $endUserIPAddress;
				
}


