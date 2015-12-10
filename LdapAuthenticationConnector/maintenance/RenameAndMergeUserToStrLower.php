<?php

/**
 * Maintenance script
 *
 * @file
 * @ingroup Maintenance
 * @author Patric Wirth <wirth@hallowelt.biz>
 * @licence GNU General Public Licence 2.0 or later
 */
$sBaseDir = dirname(dirname(dirname(dirname(dirname(__FILE__)))));
require_once( "$sBaseDir/maintenance/Maintenance.php" );

class RenameAndMergeUserToStrLower extends Maintenance {
	protected static $aRequiredClasses = array(
		'MergeUser', //Extension UserMerge
		'UserMergeConnector', //Extension UserMergeConnector
		'BsCoreHooks', //Extension BlueSpiceFoundation
		'RenameuserSQL', //Extension RenameUser
	);
	protected $iStartID = 2;
	protected $bProtectSysops = true;
	protected $bExecute = false;
	protected $oPerformer = null;

	private static $aUser = null;
	private static $aHandledUserIDs = array();

	public function __construct() {
		parent::__construct();

		$this->addOption(
			'startID',
			'Define the userID to start with (default is 2)',
			false,
			false
		);
		$this->addOption(
			'protectSysops',
			'Sysops will not be merged (default is true)',
			false,
			false
		);
		$this->addOption(
			'execute',
			'Really executes the script (default is false)',
			false,
			false
		);
		$this->addOption(
			'performer',
			'Username of User to use as perfromer of the script (default is WikiSysop)',
			false,
			false
		);
	}

	public function execute() {
		$this->iStartID = (int) $this->getOption(
			'startID',
			2
		);
		$this->bProtectSysops = (bool) $this->getOption(
			'protectSysops',
			true
		);
		$this->bExecute = (bool) $this->getOption(
			'execute',
			false
		);
		$sPerformerName = (string) $this->getOption(
			'perfromer',
			'WikiSysop'
		);
		$this->oPerformer = User::newFromName( $sPerformerName );
		$oSpecialUserMerge = SpecialPage::getTitleFor('UserMerge');

		echo "Getting started...\n\n";
		$oStatus = $this->checkRequirements();
		if( !$oStatus->isGood() ) {
			echo $oStatus->getWikiText()."\n";
			return;
		}

		echo "Getting double users...\n";
		$aUser = $this->getMergeUsers();
		if( empty($aUser) ) {
			echo "...nothing to do here\n";
		}

		foreach( $aUser as $sLowerName => $aIDs ) {
			$i = 0;
			while( isset($aIDs[$i+1]) ) {
				self::$aHandledUserIDs[] = $aIDs[$i];
				$oUserFrom = User::newFromid( $aIDs[$i] );
				$oUserTo = User::newFromid( $aIDs[$i+1] );
				echo "merge {$oUserFrom->getName()} => {$oUserTo->getName()}\n";

				$oMergeUser = new MergeUser(
					$oUserFrom,
					$oUserTo,
					new UserMergeLogger()
				);
				if( $this->bExecute ) {
					try {
						$oMergeUser->merge( $this->oPerformer );
						$oMergeUser->delete(
							$this->oPerformer,
							array( $oSpecialUserMerge, 'msg' )
						);
					} catch( Exception $e ) {
						echo $e->getMessage();
					}
				}
				$i++;
			}
		}

		echo "Getting users to rename...\n";
		$aUsers = $this->getRenamingUsers();
		if( empty($aUsers) ) {
			echo "...nothing to do here\n";
		}
		foreach( $aUsers as $iID => $sToName ) {
			$rename = new RenameuserSQL(
				User::newFromId($iID)->getName(),
				$sToName,
				$iID
			);
			return;
			if( !$rename->rename() ) {
				return;
			}
		}
	}

	protected function checkRequirements() {
		foreach( static::$aRequiredClasses as $sClassName ) {
			if( class_exists($sClassName) ) {
				continue;
			}
			return Status::newFatal(
				"Searched very hard but couldnt find the class: $sClassName"
			);
		}
		if( is_null($this->oPerformer) || $this->oPerformer->getId() == 0 ) {
			$sPerformerName = (string) $this->getOption(
				'perfromer',
				'WikiSysop'
			);
			return Status::newFatal(
				"Performing is is not valid $sPerformerName"
			);
		}
		return Status::newGood(':)');
	}

	protected function getRenamingUsers( $a = array() ) {
		foreach($this->getUsers() as $iID => $sName) {
			if( in_array($iID, self::$aHandledUserIDs) ) {
				continue;
			}
			$sToName = ucfirst( strtolower($sName) );
			if( $sName === $sToName ) {
				continue;
			}
			$a[$iID] = $sToName;
		}
		return $a;
	}
	/**
	 * Gets caseinsensitive double users (name=>(1,2,3))
	 * @param array $a
	 * @return array
	 */
	protected function getMergeUsers( $a = array() ) {
		//Welcome to array nightmare
		$aLower = array_map( 'strtolower', $this->getUsers() );
		$aUniqueLower = array_unique( $aLower );
		$aDoubleEntries = array_diff_assoc( $aLower, $aUniqueLower );
		if( empty($aDoubleEntries) ) {
			return $a;
		}

		$aAllDoubleEntries = array_intersect( $aLower, $aDoubleEntries );
		foreach( $aAllDoubleEntries as $iID => $sValue ) {
			if( !isset($a[$sValue]) ) {
				$a[$sValue] = array();
			}
			$a[$sValue][] = $iID;
		}
		return $a;
	}

	protected function getUsers( ) {
		if( !is_null(self::$aUser) ) {
			return self::$aUser;
		}
		$aFields = array('user_id', 'user_name');
		$aConditions = $this->iStartID > 1
			? array("user_id >= $this->iStartID")
			: array()
		;
		$aOptions = array(
			"ORDER BY" => 'user_id asc'
		);
		self::$aUser = array();
		foreach( wfGetDB( DB_SLAVE )->select('user', $aFields, $aConditions, __METHOD__, $aOptions) as $o ) {
			self::$aUser[$o->user_id] = $o->user_name;
		}
		return self::$aUser;
	}
}

$maintClass = 'RenameAndMergeUserToStrLower';
if ( defined( 'RUN_MAINTENANCE_IF_MAIN' ) ) {
	require_once( RUN_MAINTENANCE_IF_MAIN );
} else {
	require_once( DO_MAINTENANCE ); # Make this work on versions before 1.17
}