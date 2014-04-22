<?php
class Indi_Controller_Front extends Indi_Controller{
	public $emailPattern = "/^([a-zA-Z0-9_\-\.]+)@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.)|(([a-zA-Z0-9\-]+\.)+))([a-zA-Z]{2,4}|[0-9]{1,3})(\]?)$/";
	public $datePattern = "/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}$/";
	public $urlPattern = "/^\b([\d\w\.\/\+\-\?\:]*)((ht|f)tp(s|)\:\/\/|[\d\d\d|\d\d]\.[\d\d\d|\d\d]\.|www\.|\.tv|\.ac|\.com|\.edu|\.gov|\.int|\.mil|\.net|\.org|\.biz|\.info|\.name|\.pro|\.museum|\.co|\.ru)([\d\w\.\/\%\+\-\=\&amp;\?\:\\\&quot;\'\,\|\~\;\b]*)$/";
	public function preDispatch(){

        d(Indi::uri());
        die('sd');
		// Для XHR
		header('Access-Control-Allow-Origin: *');

		// Определяем текущий раздел, ищем его в базе
		$this->section = Indi::model('Fsection')->fetchRow('`alias` = "' . Indi::uri()->section . '"');
		$this->view->section = Indi::trail()->section;
		if (Indi::trail()->section->type == 's' && $this->action == 'index') {
			$this->action = Indi::trail()->section->index;
			if (preg_match('/^\$/', Indi::trail()->section->where)) {
				eval('$this->identifier = ' . Indi::trail()->section->where . ';');
			} else {
				eval('$where = "' . Indi::trail()->section->where . '";');
				$this->identifier = Indi::model(Indi::trail()->section->entityId)->fetchRow($where)->id;
			}
		}
		Indi::registry('request', $this->params);
		
		// Определяем текущее действие, ищем его в базе
		$this->action = Indi::model('Faction')->fetchRow('`alias` = "' . $this->action . '"');
		if(!Indi::model('Fsection2faction')->fetchRow('`fsectionId` = "' . Indi::trail()->section->id . '" AND `factionId` = "' . $this->action->id . '"')) {
			$this->action = null;
		}
		$this->view->action = $this->action;
		
		// Трейл
		$this->trail = new Indi_Trail_Frontend(Indi::trail()->section->alias, $this->identifier, $this->action->alias, null, $this->params);

		// Определяем текущее id записи из таблицы fsection2faction, ищем его в базе чтобы по нему вытащить
		// данные о том, какие  зависимые количества, зависимые множества и записи - соответствующие внешним
		// ключам, нужно автоматически выташить
		$this->section2action = Indi::model('Fsection2faction')->fetchRow('`fsectionId` = "' . Indi::trail()->section->id . '" AND `factionId` = "' . $this->action->id . '"');
		// Для хэлперов seoTitle, seoKeywords и seoDescription
		$this->view->section2actionId = $this->section2action->id;

		// Если к разделу прикреплена сущность, то назначаем текущаю модель, соответствующую этой сущности
		if (Indi::trail()->section->entityId) $this->model = Indi::model(Indi::trail()->section->entityId);

		// Стандартные задачи
		$this->preMaintenance();
        $this->maintenance();

		$this->view->request = $this->params;

        // Куски
        $this->view->blocks = Indi::blocks();

    }
	
	public function postDispatch($die = true){
		$this->view->section = Indi::trail()->section;
		$this->view->indexParams = $_SESSION['indexParams'][Indi::trail()->section->alias];
		if ($this->section2action->imposition) $this->view->imposition = $this->section2action->imposition;
		$this->view->rowset = $this->rowset;
		if($this->action->alias != 'index' && $this->identifier) $this->view->row = Indi::trail()->row;
		$this->view->params = $this->params;
		$this->view->request = $this->getRequest();
		$this->view->staticPages = Indi::model('Staticpage')->fetchAll('`toggle` = "y"', 'title')->toArray();
		$this->view->visitors = $this->visitors();

        // Меню
        if (Indi::model('Entity')->fetchRow('`table` = "menu"') && !$this->view->menu) {
            $menu = Indi::model('Menu')->init();
            $this->view->menu = $menu;
        }

        $this->view->get = $this->get;
		if ($this->row) $this->view->row = $this->row;
		// Если действие обычное
		if ($this->section2action->type != 'j') {
			if (!$this->view->action->alias) {
				$this->view->action = new stdClass();
				$this->view->action->alias = 'index';
				$this->view->controller = 'error';
				$this->view->bodyClass = 'not-found-page';
			}
			$out = $this->view->render('index.php');
		// Иначе если действие предназначено для обработки XHR, рендерим только вью действия, без хэдэра и футера
		} else {
			$out = $this->view->render(Indi::trail()->section->alias . '/' . $this->action->alias . '.php');
		}
		if ($GLOBALS['enableSeoUrls'] == 'true') $out = Indi_Uri::sys2seo($out);
		$out = $this->subdomainMaintenance($out);
		$out = $this->httpsMaintenance($out);

        if (STD) {
            $out = preg_replace('/(<link[^>]+)(href)=("|\')\//', '$1$2=$3' . STD . '/', $out);
            $out = preg_replace('/(<script[^>]+)(src)=("|\')\//', '$1$2=$3' . STD . '/', $out);
            $out = preg_replace('/(<img[^>]+)(src)=("|\')\//', '$1$2=$3' . STD . '/', $out);
        }
		
		if (isset(Indi::get()->p))echo mt();
        if ($die) die($out); else return $out;
	}
	public function getOrder($orderById, $dir, $condition = null){
		if (!$this->masterOrder) {
			if (!$orderById) return null;
	//		Indi::post()->sort = 'identifier';
			Indi::post()->dir = $dir;
			// check if field (that is to be sorted by) store relation
			$entityId = false;	
			$orderBy = Indi::model('OrderBy')->fetchRow('`id` = "' . $orderById . '"');
			if ($orderBy->orderBy == "e") return $orderBy->expression . ' ' . Indi::post()->dir;
			$field = $orderBy->foreign('fieldId');
		} else {
			if (Indi::trail()->section->orderBy == 'e') {
				return Indi::trail()->section->orderExpression;
			} else {
				$field = Indi::trail()->section->foreign('orderColumn');
				Indi::post()->dir = Indi::trail()->section->orderDirection;
			}
		}
		if($field->relation || $field->satellite) {
			$entityId = $field->relation;
			$fieldId  = $field->id;
			$satellite = $field->satellite;
		}

		// get distinct entity ids that will be used to initialize models and retrieve rowsets
		if ($entityId) {
			// get distinct ids of foreign rows
			$query = 'SELECT DISTINCT `' . $field->alias . '` AS `id` FROM `' . Indi::model(Indi::trail()->section->entityId)->name() . '` WHERE 1 ' . ($condition ? ' AND ' . $condition : '');
			$result = $this->db->query($query)->fetchAll();
			if (count($result)) {
				// get distinct foreign key values, to avoid twice or more calculating title for equal ids
				for ($i = 0; $i < count($result); $i++) $ids[] = $result[$i]['id'];

				// create temporary table
				$tmpTable = 'sorting';

				$query = 'DROP TABLE IF EXISTS `' . $tmpTable . '`;';
				$this->db->query($query);

				$query = 'CREATE TEMPORARY TABLE `' . $tmpTable . '` (`id` VARCHAR(255) NOT NULL, `title` VARCHAR(255) NOT NULL);';
				$this->db->query($query);

				$query = ' INSERT INTO `' . $tmpTable . '` ';
				if ($entityId == 6) {
					$condition  = '`alias` IN ("' . implode('","', $ids) . '")';
					$condition .= ' AND `fieldId` = "' . $fieldId . '"';
					$query .= 'VALUES ';
					$foreignRowset = Indi::model($entityId)->fetchAll($condition);
					foreach ($foreignRowset as $foreignRow) {
						$values[] = '("' . $foreignRow->alias . '","' . $foreignRow->title . '")';
					}
					$query .= implode(',', $values) . ';';
				} else {
					// in this block we investigate - have the 'getTitle' method been redeclared in child *_Row class, so if no - it
					// mean that we can get titles for foreign keys  directly from 'title' column on corresponding foreign table
					// and there is no need to preform any modifications on them before output in json format
					// this shit is need it to avoid unneeded abuse to mysql server - to improve performance
					$entity = Indi::model('Entity')->fetchRow('`id` = "' . $entityId . '"')->toArray();
					$modelsDirPath = trim($_SERVER['DOCUMENT_ROOT'] . '/www', '/') . '/application/';

					// get filename of row class
					$file = $backendModulePath . 'models/' . str_replace('_', '/', Indi::model($entityId)->rowClass()) . '.php';
					if (file_exists($file)) $code = file_get_contents($file);

					// if function 'getTitle' was not redeclared
					if (!strpos($code, 'function getTitle(')) {
						$query .= 'SELECT `id`,`title` FROM `' . Indi::model($entityId)->name() . '` WHERE `id` IN (' . implode(',', $ids) . ');';
					} else {
						// prepare and put data into temporary table
						$foreignRowset = Indi::model($entityId)->fetchAll('`id` IN (' . implode(',', $ids) . ')');
						
						$query .= 'VALUES ';
						foreach ($foreignRowset as $foreignRow) {
							$values[] = '(' . $foreignRow->id . ', "' . str_replace('"', '&quote;', $foreignRow->title) . '")';
						}
						$query .= implode(',', $values) . ';';
					}
				}
				$this->db->query($query);

				// sort data in temporary table by 'title' field
				$result = $this->db->query('SELECT `id`,`title` FROM `' . $tmpTable . '` ORDER BY `title` ' . Indi::post()->dir)->fetchAll();

				$query = 'DROP TABLE `' . $tmpTable . '`;';
				$this->db->query($query);

				// get array of ids
				$ids = array();
				for ($i = 0; $i < count($result); $i++) $ids[] = $result[$i]['id'];
		
				$order = 'POSITION(CONCAT("\'", `' . $field->alias . '`	, "\'") IN "\'' . implode("','", $ids) . '\'") ASC';
			} else $ids = array();
			
		// if column store foreign keys that are pointing to variable entties
		} else if ($satellite){
			$rowset = Indi::trail()->model->fetchAll('1 ' . ($condition ? ' AND ' . $condition : ''));
			$tmp = array();
			foreach ($rowset as $row) {
				$tmp[] = array('id' => $row->id, 'title' => $row->foreign(Indi::post()->sort)->title);
			}
			if (count($tmp)) {
				// create temporary table
				$tmpTable = 'sorting';

				$query = 'DROP TABLE IF EXISTS `' . $tmpTable . '`;';
				$this->db->query($query);

				$query = 'CREATE TEMPORARY TABLE `' . $tmpTable . '` (`id` VARCHAR(255) NOT NULL, `title` VARCHAR(255) NOT NULL);';
				$this->db->query($query);

				$query = 'INSERT INTO `' . $tmpTable . '` ';
				$values = array();
				for ($i = 0; $i < count($tmp); $i ++) {
					$values[] = '(' . $tmp[$i]['id'] . ', "' . $tmp[$i]['title'] . '")';
				}
				$query .= 'VALUES ' . implode(', ', $values) . ';';
				$this->db->query($query);

				// sort data in temporary table by 'title' field
				$result = $this->db->query('SELECT `id`,`title` FROM `' . $tmpTable . '` ORDER BY `title` ' . Indi::post()->dir)->fetchAll();

				$query = 'DROP TABLE `' . $tmpTable . '`;';
				$this->db->query($query);

				// get array of ids
				$ids = array();
				for ($i = 0; $i < count($result); $i++) $ids[] = $result[$i]['id'];
		
				$order = 'POSITION(CONCAT("\'", `id`, "\'") IN "\'' . implode("','", $ids) . '\'") ASC';
			}
		} else {
			$order = $field->alias . ' ' . Indi::post()->dir;
		}
        $order = trim($order) ? $order : null;
		return $order;
	}
	public function getRowsetParams(){
		if (isset(Indi::post()->indexPage)) {
			$previousPage = $_SESSION['indexParams'][Indi::trail()->section->alias]['page'];
			$_SESSION['indexParams'][Indi::trail()->section->alias]['page'] = (int) Indi::post()->indexPage;
			
		}
		if (isset(Indi::post()->indexLimit)) {
			if (Indi::post()->indexLimit != $_SESSION['indexParams'][Indi::trail()->section->alias]['limit']) {
				$_SESSION['indexParams'][Indi::trail()->section->alias]['page'] = 1;
				$noDirChange = true;
			}
			$_SESSION['indexParams'][Indi::trail()->section->alias]['limit'] = (int) Indi::post()->indexLimit;
		}
		if ($this->rowsetFilter && is_array($this->rowsetFilter)){
			foreach ($this->rowsetFilter as $index => $value) {
//				if (array_key_exists($index, Indi::post()->indexWhere)) Indi::post()->indexWhere[$index] = $value;
			}
		}
        if (Indi::trail(1)){
            if (Indi::trail()->section->parentSectionConnector) {
                $parentSectionConnectorAlias = Indi::trail()->section->foreign('parentSectionConnector')->alias;
                Indi::post()->indexWhere[1] = '`' . $parentSectionConnectorAlias . '` = "' . Indi::trail(1)->row->$parentSectionConnectorAlias .'"';
            } else {
                $alias = Indi::trail(1)->model->name() . 'Id';
                $fieldR = Indi::model('Field')->fetchRow('`entityId` = "' . Indi::trail()->section->entityId . '" AND `alias` = "' . $alias . '"');
                if ($fieldR->storeRelationAbility == 'one') {
                    Indi::post()->indexWhere[1] = '`' . $alias . '` = "' . Indi::trail(1)->row->id .'"';
                } else {
                    Indi::post()->indexWhere[1] = 'FIND_IN_SET("' . Indi::trail(1)->row->id .'", `' . $alias . '`)';
                }
            }
        }
		if (Indi::trail()->section->filter) {
            Indi::$cmpTpl = Indi::trail()->section->filter; eval(Indi::$cmpRun); Indi::trail()->section->filter = Indi::$cmpOut;
			Indi::post()->indexWhere[2] = Indi::trail()->section->filter;
		}
		if (isset(Indi::post()->indexWhere)){
			foreach (Indi::post()->indexWhere as $filterParam => $requiredValue) {
				if (!$requiredValue) {
					unset($_SESSION['indexParams'][Indi::trail()->section->alias]['where'][$filterParam]);
				} else {
					if ($_SESSION['indexParams'][Indi::trail()->section->alias]['where'][$filterParam] != $requiredValue) {
						$_SESSION['indexParams'][Indi::trail()->section->alias]['page'] = 1;
					}
					$_SESSION['indexParams'][Indi::trail()->section->alias]['where'][$filterParam] = $requiredValue;
				}
			}
		}
		if (isset(Indi::post()->indexOrder)) {
			if (!isset($_SESSION['indexParams'][Indi::trail()->section->alias]['order'])){
				$_SESSION['indexParams'][Indi::trail()->section->alias]['dir'] = Indi::trail()->section->orderBy == 'c' ? Indi::trail()->section->orderDirection : 'ASC';
			} else if ($_SESSION['indexParams'][Indi::trail()->section->alias]['order'] == Indi::post()->indexOrder && $previousPage == Indi::post()->indexPage) {
				if (isset(Indi::post()->indexDir)) {
					$_SESSION['indexParams'][Indi::trail()->section->alias]['dir'] = in_array(Indi::post()->indexDir, array('DESC', 'ASC')) ? Indi::post()->indexDir : 'ASC';
				} else {
					$_SESSION['indexParams'][Indi::trail()->section->alias]['dir'] = $_SESSION['indexParams'][Indi::trail()->section->alias]['dir'] == 'ASC' ? ($noDirChange ? 'ASC' : 'DESC') : ($noDirChange ? 'DESC' : 'ASC');
				}
			}
			$_SESSION['indexParams'][Indi::trail()->section->alias]['order'] = (int) Indi::post()->indexOrder;
		} else {
			if (Indi::trail()->section->orderBy == 'c') {
				$_SESSION['indexParams'][Indi::trail()->section->alias]['order'] = Indi::model('OrderBy')->fetchRow('`fsectionId` = "' . Indi::trail()->section->id . '" AND `fieldId` = "' . Indi::trail()->section->orderColumn . '"')->id;
				$_SESSION['indexParams'][Indi::trail()->section->alias]['dir'] = Indi::trail()->section->orderDirection;
			} else {
				$_SESSION['indexParams'][Indi::trail()->section->alias]['order'] = Indi::trail()->section->orderExpression;
				if (stripos(Indi::trail()->section->orderExpression, 'asc') === false && stripos(Indi::trail()->section->orderExpression, 'desc') === false) {
					$_SESSION['indexParams'][Indi::trail()->section->alias]['dir'] = 'ASC';
				}
			}
		}
		if (!$_SESSION['indexParams'][Indi::trail()->section->alias]['page']) $_SESSION['indexParams'][Indi::trail()->section->alias]['page'] = 1;
		if (!$_SESSION['indexParams'][Indi::trail()->section->alias]['limit']) $_SESSION['indexParams'][Indi::trail()->section->alias]['limit'] = Indi::trail()->section->defaultLimit;
		if (!$_SESSION['indexParams'][Indi::trail()->section->alias]['where']) $_SESSION['indexParams'][Indi::trail()->section->alias]['where'] = null;
		if (!$_SESSION['indexParams'][Indi::trail()->section->alias]['order']) {
			if (Indi::trail()->section->orderBy == 'e' || Indi::trail()->section->orderColumn) {
				$this->masterOrder = true;
			} else {
				$_SESSION['indexParams'][Indi::trail()->section->alias]['order'] = key(Indi::trail()->section->getOrder());
			}
		}
        if (Indi::trail()->section->orderBy == 'e' || Indi::trail()->section->orderColumn) {
            $this->masterOrder = true;
        }
		$where = null;
		if (is_array($_SESSION['indexParams'][Indi::trail()->section->alias]['where'])) {
			foreach ($_SESSION['indexParams'][Indi::trail()->section->alias]['where'] as $filterParam => $requiredValue) {
				if ((int)$filterParam == $filterParam) {
					$where[] = $requiredValue;
				} else {
					if (strpos($filterParam, 'From') && Indi::trail()->section->getFilter(str_replace('From', '', $filterParam))->type == 'b'){
						$where[] = '`' . str_replace('From', '', $filterParam) . '` >= "' . $requiredValue . '"';
					} else if (strpos($filterParam, 'To') && Indi::trail()->section->getFilter(str_replace('To', '', $filterParam))->type == 'b') {
						$where[] = '`' . str_replace('To', '', $filterParam) . '` <= "' . $requiredValue . '"';
					} else {
						$findInSet = false;
						foreach (Indi::trail()->fields as $field) if ($field['alias'] == $filterParam && $field['storeRelationAbility'] == 'many') $findInSet = true;
						if ($findInSet) {
							$idsToFind = explode(',', $requiredValue);
							$find = array();
							for ($i = 0; $i < count($idsToFind); $i++) {
								$find[] = 'FIND_IN_SET("' . $idsToFind[$i] . '", `' . $filterParam . '`)';
							}
							$where[] = implode(' AND ',  $find);
						} else {
							$where[] = '`' . $filterParam . '` LIKE "' . $requiredValue . '"';
						}
					}
				}
			}
			if (is_array($where)) $where = implode(' AND ', $where);
		}
		$page = $_SESSION['indexParams'][Indi::trail()->section->alias]['page'];
		$limit = $_SESSION['indexParams'][Indi::trail()->section->alias]['limit'];
		$order = $_SESSION['indexParams'][Indi::trail()->section->alias]['order'];
		$dir = $_SESSION['indexParams'][Indi::trail()->section->alias]['dir'];
		return array('page' => $page, 'limit' => $limit, 'order' => $order, 'where' => $where, 'dir' => $dir);
	}
	public function maintenance(){

		// Выдергивание независимых множеств
		$this->setIndependentRowsets();

        if (is_object($this->model) && get_class($this->model) != 'stdClass' && !$this->noMaintenance) {
            if ($this->action->maintenance == 'rs') {
	//		if ($this->model && Indi::trail()->section->type == 'r' && $this->action->alias == 'index') {
				// get rowset params and get rowset according to them

                $rp = $this->getRowsetParams();
                die('ss');
				if ($tree = $this->model->treeColumn()) {
					$this->rowset = $this->model->fetchTree($rp['where'], trim($this->getOrder($rp['order'], $rp['dir'])));
				} else {
					if ($this->exclusiveRowsetParams) {
						$this->rowset = $this->model->fetchAll($this->exclusiveRowsetParams['where'], $this->exclusiveRowsetParams['order'], $this->exclusiveRowsetParams['limit'], $this->exclusiveRowsetParams['page']);
					} else {
						$this->rowset = $this->model->fetchAll($rp['where'], trim($this->getOrder($rp['order'], $rp['dir'])), $rp['limit'], $rp['page']);
					}
				}
				// set dependent counts or related items
				$info = $this->section2action->getInfoAboutDependentCountsToBeGot();
				if ($info->count()) $this->rowset->setDependentCounts($info);
				
				// set dependent rowsets of related items
				$info = $this->section2action->getInfoAboutDependentRowsetsToBeGot();
				if ($info->count()) $this->rowset->setDependentRowsets($info);

				// set join needed foreign rows on foreign keys
				$info = $this->section2action->getInfoAboutForeignRowsToBeGot();
				if ($info->count()) $this->rowset->foreign($info);
//			} else if ($this->model && $this->identifier) {
			} else if ($this->action->maintenance == 'r') {
				//get row
				if ($this->identifier == (int) $this->identifier) {
					$where = '`id` = "' . (int) $this->identifier . '"';
				} else {
					$where = $this->identifier;
				}
				if ($this->row = $this->model->fetchRow($where)) {
					// set dependent rowsets of related items
					$info = $this->section2action->getInfoAboutDependentRowsetsToBeGot();
					if ($info->count()) $this->row->setDependentRowsets($info);
					// set join needed foreign rows on foreign keys
					$info = $this->section2action->getInfoAboutForeignRowsToBeGot();
					if ($info->count()) $this->row->foreign($info);
					
					$this->view->row = $this->row;
				} else {
					//readfile('http://' . $_SERVER['HTTP_HOST'] . '/404/');
					die('No row found');
				}
			}
		}
		if (Indi::trail()->section->type == 's') {
			$this->{Indi::trail()->section->index . 'Action()'};
			eval('$this->' . Indi::trail()->section->index . 'Action();');
		}
	}
	public function setIndependentRowsets(){
		if ($this->section2action) {
			$info = $this->section2action->getInfoAboutIndependentCountsToBeGot();
			$join = Indi::model('JoinFkForIndependentRowset');
			foreach ($info as $entity) {
				if ($entity->calculatedColumns) {
					if (preg_match('/\$/', $entity->calculatedColumns)) {
						eval('$calc = \'' . $entity->calculatedColumns . '\';');
					} else {
						$calc = $entity->calculatedColumns;
					}
				} else {
					$calc = null;
				}

				if ($entity->filter) {
					if (preg_match('/\$/', $entity->filter)) {
						eval('$entity->filter = \'' . $entity->filter . '\';');
					}
					$where = $entity->filter;
				} else {
					$where = null;
				}
				$order = $entity->orderBy == 'c' && $entity->foreign('fieldId')->alias ? $entity->foreign('fieldId')->alias  . ' ' . $entity->orderDirection : $entity->expression;
				if (preg_match('/\$/', $order)) {
					eval('$order = \'' . $order . '\';');
				}

				$limit = $entity->limit ? $entity->limit : null;
				$page = $_SESSION['rowsetParams'][Indi::trail()->section->alias][$this->action->alias]['independent'][$entity->alias]['page'];if (!$page) $page = 1;
				
				$rowset = Indi::model($entity->entityId)->fetchAll($where, $order ? $order : null, $limit, $page, $calc);
				$joins = $join->fetchAll('`independentRowsetId` = "' . $entity->id . '"');
				if ($joins->count()) {
					$rowset->foreign($joins);
				}
				$independentRowsets[$entity->alias] = $entity->returnAs == 'o' ? $rowset : $rowset->toArray();
			}
			$this->view->independentRowsets = $independentRowsets;
		}
	}
	public function __call($action, $arguments) {
	}
	public function preMaintenance(){
	}
	public function rowsetParams(){
		$_SESSION['rowsetParams'][Indi::trail()->section->alias][$this->action->alias]['independent'][Indi::post()->rowsetAlias]['page'] = Indi::post()->page;
		die();
	}
	public function subdomainMaintenance($html){
		if (Indi::registry('subdomains')) {
			$subdomains = Indi::registry('subdomains');
			for ($i = 0; $i < count($subdomains); $i++) {
				$html = preg_replace('/(href|action)="\/' . $subdomains[$i] . '\//', '$1="http://' . $subdomains[$i] . '.' . Indi::ini()->general->domain .'/', $html);
			}
		}
		if (Indi::registry('subdomain')) {
			$html = preg_replace('/(href|action)="\//', '$1="http://' . Indi::ini()->general->domain.'/', $html);
		}
		return $html;
	}
	public function httpsMaintenance($html) {
		if ($_SERVER['SERVER_PORT'] == 443) {
			$html = preg_replace('/(<link.*href=)"\//ui', '$1"https://' . Indi::ini()->general->domain . '/', $html);
			$html = preg_replace('/(<link.*)href="http:/ui', '$1 href="https:', $html);
			$html = preg_replace('/(<script.*)src="http:/ui', '$1 src="https:', $html);
		}
		return $html;
	}
}