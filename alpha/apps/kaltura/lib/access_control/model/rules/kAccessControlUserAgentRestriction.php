<?php
/**
 * @package Core
 * @subpackage model.data
 * 
 * Old IP address restriction for backward compatibility
 */
class kAccessControlUserAgentRestriction extends kAccessControlRestriction
{
	/**
	 * @var kUserAgentCondition
	 */
	private $condition;
	
	/**
	 * @param accessControl $accessControl
	 */
	public function __construct(accessControl $accessControl = null)
	{
		parent::__construct($accessControl);
		$this->setActions(array(new kAccessControlAction(accessControlActionType::BLOCK)));
		
		$this->condition = new kUserAgentCondition(true);
		if($accessControl)
		{
			$strArray = unserialize($accessControl->getFromCustomData(accessControl::IP_ADDRESS_RESTRICTION_COLUMN_NAME));
			$this->setUserAgentRestrictionType($strArray['type']);
			$this->setUserAgentRegexList($strArray['userAgentRegexList']);
		}
		
		$this->setConditions(array($this->getCondition()));
	}

	/* (non-PHPdoc)
	 * @see kRule::applyContext()
	 */
	public function applyContext(kEntryContextDataResult $context)
	{
		$fulfilled = parent::applyContext($context);
		if($fulfilled)
			$context->setIsUserAgentRestricted(true);
			
		return $fulfilled;
	}

	/**
	 * @return kUserAgentCondition
	 */
	private function getCondition()
	{
		if(!$this->condition && count($this->getConditions()))
			$this->condition = reset($this->getConditions());
			
		return $this->condition;
	}

	/**
	 * @param int $type
	 */
	function setUserAgentRestrictionType($type)
	{
		$this->getCondition()->setNot($type == kAccessControlRestriction::RESTRICTION_TYPE_ALLOW_LIST);
	}
	
	/**
	 * @param string $userAgentRegexList
	 */
	function setUserAgentRegexList($values)
	{
		$values = explode(',', $values);
		$this->getCondition()->setValues($values);
	}
	
	/**
	 * @return int
	 */
	function getUserAgentRestrictionType()
	{
		return $this->getCondition()->getNot() ? kAccessControlRestriction::RESTRICTION_TYPE_ALLOW_LIST : kAccessControlRestriction::RESTRICTION_TYPE_RESTRICT_LIST;	
	}
	
	/**
	 * @return string
	 */
	function getUserAgentRegexList()
	{
		return implode(',', $this->getCondition()->getStringValues());
	}
}

