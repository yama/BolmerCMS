<?php namespace Bolmer\Operations;

class Document
{
    /** @var \Bolmer\Pimple $_inj коллекция зависимостей */
    private $_inj = null;

    /** @var \Bolmer\Core $_core */
    protected $_core = null;

    /**
     * Конструктор класса \Bolmer\Operations\Document
     *
     * @param \Pimple $inj коллекция зависимостей
     */
    public function __construct(\Pimple $inj)
    {
        $this->_inj = $inj;
        $this->_core = $inj['core'];
    }

    /**
     * Returns an array of child IDs belonging to the specified parent.
     *
     * @param int $id The parent resource/document to start from
     * @param int $depth How many levels deep to search for children, default: 10
     * @param array $children Optional array of docids to merge with the result.
     * @return array Contains the document Listing (tree) like the sitemap
     */
    public function getChildIds($id, $depth = 10, $children = array())
    {

        // Initialise a static array to index parents->children
        static $documentMap_cache = array();
        if (!count($documentMap_cache)) {
            foreach ($this->_core->documentMap as $document) {
                foreach ($document as $p => $c) {
                    $documentMap_cache[$p][] = $c;
                }
            }
        }

        // Get all the children for this parent node
        if (isset($documentMap_cache[$id])) {
            $depth--;

            foreach ($documentMap_cache[$id] as $childId) {
                $pkey = (strlen($this->_core->aliasListing[$childId]['path']) ? "{$this->_core->aliasListing[$childId]['path']}/" : '') . $this->_core->aliasListing[$childId]['alias'];
                if (!strlen($pkey)) $pkey = "{$childId}";
                $children[$pkey] = $childId;

                if ($depth) {
                    $children += $this->getChildIds($childId, $depth);
                }
            }
        }
        return $children;
    }

    /**
     * Returns an array of all parent record IDs for the id passed.
     *
     * @param int $id Docid to get parents for.
     * @param int $height The maximum number of levels to go up, default 10.
     * @return array
     */
    public function getParentIds($id, $height = 10)
    {
        $parents = array();
        while ($id && $height--) {
            $thisid = $id;
            $id = $this->_core->aliasListing[$id]['parent'];
            if (!$id) break;
            $parents[$thisid] = $id;
        }
        return $parents;
    }

    /**
     * Get the parent docid of a document
     *
     * @param int $id ID документа
     * @return int
     */
    function getParentId($id)
    {
        return $this->_core->aliasListing[$id]['parent'];
    }

    /**
     * Returns the ultimate parent of a document
     *
     * @param int $id Docid to get ultimate parent.
     * @return int
     */
    function getUltimateParentId($id)
    {
        $last_id = 0;
        while ($id) {
            $last_id = $id;
            $id = $this->_core->aliasListing[$id]['parent'];
        }
        return $last_id;
    }

    /**
     * Gets all child documents of the specified document, including those which are unpublished or deleted.
     *
     * @param int $id The Document identifier to start with
     * @param string $sort Sort field
     *                     Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string $fields Default: id, pagetitle, description, parent, alias, menutitle
     * @return array
     */
    public function getAllChildren($id = 0, $sort = 'menuindex', $dir = 'ASC', $fields = 'id, pagetitle, description, parent, alias, menutitle')
    {
        $tblsc = $this->_core->getTableName("BDoc");
        $tbldg = $this->_core->getTableName("BDocGroupList");
        // modify field names to use sc. table reference
        $fields = 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
        $sort = 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
        // get document groups for current user
        if ($docgrp = $this->_inj['user']->getUserDocGroups())
            $docgrp = implode(",", $docgrp);
        // build query
        $access = ($this->_core->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
            (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql = "SELECT DISTINCT $fields FROM $tblsc sc
              LEFT JOIN $tbldg dg on dg.document = sc.id
              WHERE sc.parent = '$id'
              AND ($access)
              GROUP BY sc.id
              ORDER BY $sort $dir;";
        $result = $this->db->query($sql);
        $resourceArray = array();
        for ($i = 0; $i < @ $this->_core->db->getRecordCount($result); $i++) {
            array_push($resourceArray, @ $this->_core->db->getRow($result));
        }
        return $resourceArray;
    }

    /**
     * Gets all active child documents of the specified document, i.e. those which published and not deleted.
     *
     * @param int $id The Document identifier to start with
     * @param string $sort Sort field
     *                     Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string $fields Default: id, pagetitle, description, parent, alias, menutitle
     * @return array
     */
    public function getActiveChildren($id = 0, $sort = 'menuindex', $dir = 'ASC', $fields = 'id, pagetitle, description, parent, alias, menutitle')
    {
        $tblsc = $this->_core->getTableName("BDoc");
        $tbldg = $this->_core->getTableName("BDocGroupList");

        // modify field names to use sc. table reference
        $fields = 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
        $sort = 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
        // get document groups for current user
        if ($docgrp = $this->_inj['user']->getUserDocGroups())
            $docgrp = implode(",", $docgrp);
        // build query
        $access = ($this->_core->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
            (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql = "SELECT DISTINCT $fields FROM $tblsc sc
              LEFT JOIN $tbldg dg on dg.document = sc.id
              WHERE sc.parent = '$id' AND sc.published=1 AND sc.deleted=0
              AND ($access)
              GROUP BY sc.id
              ORDER BY $sort $dir;";
        $result = $this->_core->db->query($sql);
        $resourceArray = array();
        for ($i = 0; $i < @ $this->_core->db->getRecordCount($result); $i++) {
            array_push($resourceArray, @ $this->_core->db->getRow($result));
        }
        return $resourceArray;
    }

    /**
     * Returns the children of the selected document/folder.
     *
     * @param int $parentid The parent document identifier
     *                      Default: 0 (site root)
     * @param int $published Whether published or unpublished documents are in the result
     *                      Default: 1
     * @param int $deleted Whether deleted or undeleted documents are in the result
     *                      Default: 0 (undeleted)
     * @param string $fields List of fields
     *                       Default: * (all fields)
     * @param string $where Where condition in SQL style. Should include a leading 'AND '
     *                      Default: Empty string
     * @param string $sort Should be a comma-separated list of field names on which to sort
     *                    Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string|int $limit Should be a valid SQL LIMIT clause without the 'LIMIT' i.e. just include the numbers as a string.
     *                          Default: Empty string (no limit)
     * @return array
     */
    public function getDocumentChildren($parentid = 0, $published = 1, $deleted = 0, $fields = "*", $where = '', $sort = "menuindex", $dir = "ASC", $limit = "")
    {
        $limit = ($limit != "") ? "LIMIT $limit" : "";
        $tblsc = $this->_core->getTableName("BDoc");
        $tbldg = $this->_core->getTableName("BDocGroupList");
        // modify field names to use sc. table reference
        $fields = 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
        $sort = ($sort == "") ? "" : 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
        if ($where != '')
            $where = 'AND ' . $where;
        // get document groups for current user
        if ($docgrp = $this->_inj['user']->getUserDocGroups())
            $docgrp = implode(",", $docgrp);
        // build query
        $access = ($this->_core->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
            (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql = "SELECT DISTINCT $fields
              FROM $tblsc sc
              LEFT JOIN $tbldg dg on dg.document = sc.id
              WHERE sc.parent = '$parentid' " . ((int)$published == 0 ? '' : "AND sc.published=$published ") . "AND sc.deleted=$deleted $where
              AND ($access)
              GROUP BY sc.id " .
            ($sort ? " ORDER BY $sort $dir " : "") . " $limit ";
        $result = $this->_core->db->query($sql);
        $resourceArray = array();
        for ($i = 0; $i < @ $this->_core->db->getRecordCount($result); $i++) {
            array_push($resourceArray, @ $this->_core->db->getRow($result));
        }
        return $resourceArray;
    }

    /**
     * Returns multiple documents/resources
     *
     * @category API-Function
     * @param array $ids Documents to fetch by docid
     *                   Default: Empty array
     * @param int $published Whether published or unpublished documents are in the result
     *                      Default: 1
     * @param int $deleted Whether deleted or undeleted documents are in the result
     *                      Default: 0 (undeleted)
     * @param string $fields List of fields
     *                       Default: * (all fields)
     * @param string $where Where condition in SQL style. Should include a leading 'AND '.
     *                      Default: Empty string
     * @param string $sort Should be a comma-separated list of field names on which to sort
     *                    Default: menuindex
     * @param string $dir Sort direction, ASC and DESC is possible
     *                    Default: ASC
     * @param string|int $limit Should be a valid SQL LIMIT clause without the 'LIMIT' i.e. just include the numbers as a string.
     *                          Default: Empty string (no limit)
     * @return array|boolean Result array with documents, or false
     */
    public function getDocuments($ids = array(), $published = 1, $deleted = 0, $fields = "*", $where = '', $sort = "menuindex", $dir = "ASC", $limit = "")
    {
        if (is_string($ids)) {
            if (strpos($ids, ',') !== false)
                $ids = explode(',', $ids);
            else
                $ids = array($ids);
        }
        if (count($ids) == 0) {
            return false;
        } else {
            $limit = ($limit != "") ? "LIMIT $limit" : ""; // LIMIT capabilities - rad14701
            $tblsc = $this->_core->getTableName("BDoc");
            $tbldg = $this->_core->getTableName("BDocGroupList");
            // modify field names to use sc. table reference
            $fields = 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
            $sort = ($sort == "") ? "" : 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $sort)));
            if ($where != '')
                $where = 'AND ' . $where;
            // get document groups for current user
            if ($docgrp = $this->_inj['user']->getUserDocGroups())
                $docgrp = implode(",", $docgrp);
            $access = ($this->_core->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
                (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
            $sql = "SELECT DISTINCT $fields FROM $tblsc sc
                    LEFT JOIN $tbldg dg on dg.document = sc.id
                    WHERE (sc.id IN (" . implode(",", $ids) . ") AND sc.published=$published AND sc.deleted=$deleted $where)
                    AND ($access)
                    GROUP BY sc.id " .
                ($sort ? " ORDER BY $sort $dir" : "") . " $limit ";
            $result = $this->_core->db->query($sql);
            $resourceArray = array();
            for ($i = 0; $i < @ $this->_core->db->getRecordCount($result); $i++) {
                array_push($resourceArray, @ $this->_core->db->getRow($result));
            }
            return $resourceArray;
        }
    }

    /**
     * Returns one document/resource
     *
     * @category API-Function
     * @param int $id docid
     *                Default: 0 (no documents)
     * @param string $fields List of fields
     *                       Default: * (all fields)
     * @param int $published Whether published or unpublished documents are in the result
     *                      Default: 1
     * @param int $deleted Whether deleted or undeleted documents are in the result
     *                      Default: 0 (undeleted)
     * @return boolean|string
     */
    public function getDocument($id = 0, $fields = "*", $published = 1, $deleted = 0)
    {
        $out = false;
        if (!empty($id)) {
            $tmpArr[] = $id;
            $docs = $this->getDocuments($tmpArr, $published, $deleted, $fields, "", "", "", 1);
            if ($docs != false) {
                $out = $docs[0];
            }
        }
        return $out;
    }

    /**
     * Returns the page information as database row, the type of result is
     * defined with the parameter $rowMode
     *
     * @param int $pageid The parent document identifier
     *                    Default: -1 (no result)
     * @param int $active Should we fetch only published and undeleted documents/resources?
     *                     1 = yes, 0 = no
     *                     Default: 1
     * @param string $fields List of fields
     *                       Default: id, pagetitle, description, alias
     * @return boolean|array
     */
    public function getPageInfo($pageid = -1, $active = 1, $fields = 'id, pagetitle, description, alias')
    {
        if ($pageid == 0) {
            return false;
        } else {
            $tblsc = $this->_core->getTableName("BDoc");
            $tbldg = $this->_core->getTableName("BDocGroupList");
            $activeSql = (int)$active == 1 ? "AND sc.published=1 AND sc.deleted=0" : "";
            // modify field names to use sc. table reference
            $fields = 'sc.' . implode(',sc.', preg_replace("/^\s/i", "", explode(',', $fields)));
            // get document groups for current user
            if ($docgrp = $this->_inj['user']->getUserDocGroups())
                $docgrp = implode(",", $docgrp);
            $access = ($this->_core->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
                (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
            $sql = "SELECT $fields
                    FROM $tblsc sc
                    LEFT JOIN $tbldg dg on dg.document = sc.id
                    WHERE (sc.id=$pageid $activeSql)
                    AND ($access)
                    LIMIT 1 ";
            $result = $this->_core->db->query($sql);
            $pageInfo = @ $this->_core->db->getRow($result);
            return $pageInfo;
        }
    }

    /**
     * Returns the parent document/resource of the given docid
     *
     * @param int $pid The parent docid. If -1, then fetch the current document/resource's parent
     *                 Default: -1
     * @param int $active Should we fetch only published and undeleted documents/resources?
     *                     1 = yes, 0 = no
     *                     Default: 1
     * @param string $fields List of fields
     *                       Default: id, pagetitle, description, alias
     * @return boolean|array
     */
    public function getParent($pid = -1, $active = 1, $fields = 'id, pagetitle, description, alias, parent')
    {
        if ($pid == -1) {
            $pid = $this->_core->documentObject['parent'];
            return ($pid == 0) ? false : $this->getPageInfo($pid, $active, $fields);
        } else
            if ($pid == 0) {
                return false;
            } else {
                // first get the child document
                $child = $this->getPageInfo($pid, $active, "parent");
                // now return the child's parent
                $pid = ($child['parent']) ? $child['parent'] : 0;
                return ($pid == 0) ? false : $this->getPageInfo($pid, $active, $fields);
            }
    }

    /**
     * Get all db fields and TVs for a document/resource
     *
     * @param string $method
     * @param string $identifier
     * @param bool $isPrepareResponse
     * @return array
     */
    public function getDocumentObject($method, $identifier, $isPrepareResponse = false)
    {
        $tblsc = $this->_core->getTableName("BDoc");
        $tbldg = $this->_core->getTableName("BDocGroupList");
        // allow alias to be full path
        if ($method == 'alias') {
            $identifier = $this->_core->cleanDocumentIdentifier($identifier);
            $method = $this->_core->documentMethod;
        }
        if ($method == 'alias' && $this->_core->getConfig('use_alias_path') && array_key_exists($identifier, $this->_core->documentListing)) {
            $method = 'id';
            $identifier = $this->_core->documentListing[$identifier];
        }
        // get document groups for current user
        if ($docgrp = $this->_inj['user']->getUserDocGroups())
            $docgrp = implode(",", $docgrp);
        // get document
        $access = ($this->_core->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
            (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql = "SELECT sc.*
              FROM $tblsc sc
              LEFT JOIN $tbldg dg ON dg.document = sc.id
              WHERE sc." . $method . " = '" . $identifier . "'
              AND ($access) LIMIT 1;";
        $result = $this->_core->db->query($sql);
        $rowCount = $this->_core->db->getRecordCount($result);
        if ($rowCount < 1) {
            if ($this->_core->getConfig('unauthorized_page')) {
                // method may still be alias, while identifier is not full path alias, e.g. id not found above
                if ($method === 'alias') {
                    $q = "SELECT dg.id FROM $tbldg dg, $tblsc sc WHERE dg.document = sc.id AND sc.alias = '{$identifier}' LIMIT 1;";
                } else {
                    $q = "SELECT id FROM $tbldg WHERE document = '{$identifier}' LIMIT 1;";
                }
                // check if file is not public
                $secrs = $this->_core->db->query($q);
                if ($secrs)
                    $seclimit = $this->_core->db->getRecordCount($secrs);
            }
            if ($seclimit > 0) {
                // match found but not publicly accessible, send the visitor to the unauthorized_page
                $this->_core->sendUnauthorizedPage();
                exit; // stop here
            } else {
                $this->_core->sendErrorPage();
                exit;
            }
        }

        # this is now the document :) #
        $documentObject = $this->_core->db->getRow($result);
        if ($isPrepareResponse === 'prepareResponse') $this->_core->documentObject = & $documentObject;
        $this->_core->invokeEvent('OnLoadDocumentObject');
        if ($documentObject['template']) {
            // load TVs and merge with document - Orig by Apodigm - Docvars
            $sql = "SELECT tv.*, IF(tvc.value!='',tvc.value,tv.default_text) as value ";
            $sql .= "FROM " . $this->_core->getTableName("BTv") . " tv ";
            $sql .= "INNER JOIN " . $this->_core->getTableName("BTvTemplate") . " tvtpl ON tvtpl.tmplvarid = tv.id ";
            $sql .= "LEFT JOIN " . $this->_core->getTableName("BTvValue") . " tvc ON tvc.tmplvarid=tv.id ";
            $sql .= "WHERE tvc.contentid = '" . $documentObject['id'] . "' AND tvtpl.templateid = '" . $documentObject['template'] . "'";
            $rs = $this->_core->db->query($sql);
            $rowCount = $this->_core->db->getRecordCount($rs);
            if ($rowCount > 0) {
                for ($i = 0; $i < $rowCount; $i++) {
                    $row = $this->_core->db->getRow($rs);
                    $tmplvars[$row['name']] = array(
                        $row['name'],
                        $row['value'],
                        $row['display'],
                        $row['display_params'],
                        $row['type']
                    );
                }
                $documentObject = array_merge($documentObject, $tmplvars);
            }
        }
        return $documentObject;
    }

    /**
     * Modified by Raymond for TV - Orig Modified by Apodigm - DocVars
     * Returns a single site_content field or TV record from the db.
     *
     * If a site content field the result is an associative array of 'name' and 'value'.
     *
     * If a TV the result is an array representing a db row including the fields specified in $fields.
     *
     * @param string $idname Can be a TV id or name
     * @param string $fields Fields to fetch from site_tmplvars. Default: *
     * @param int $docid Docid. Defaults to empty string which indicates the current document.
     * @param int $published Whether published or unpublished documents are in the result
     *                        Default: 1
     * @return boolean
     */
    public function getTemplateVar($idname = "", $fields = "*", $docid = 0, $published = 1)
    {
        if ($idname == "") {
            return false;
        } else {
            $result = $this->getTemplateVars(array($idname), $fields, $docid, $published, "", ""); //remove sorting for speed
            return ($result != false) ? $result[0] : false;
        }
    }

    /**
     * Returns an array of site_content field fields and/or TV records from the db
     *
     * Elements representing a site content field consist of an associative array of 'name' and 'value'.
     *
     * Elements representing a TV consist of an array representing a db row including the fields specified in $fields.
     *
     * @param array $idnames Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only)
     *                                               or the TV names (array elements should be names only)
     *                        Default: Empty array
     * @param string $fields Fields to fetch from site_tmplvars.
     *                        Default: *
     * @param int $docid Docid. Defaults to empty string which indicates the current document.
     * @param int $published Whether published or unpublished documents are in the result
     *                        Default: 1
     * @param string $sort How to sort the result array (field)
     *                        Default: rank
     * @param string $dir How to sort the result array (direction)
     *                        Default: ASC
     * @return boolean|array
     */
    public function getTemplateVars($idnames = array(), $fields = "*", $docid = 0, $published = 1, $sort = "rank", $dir = "ASC")
    {
        if (($idnames != '*' && !is_array($idnames)) || count($idnames) == 0) {
            return false;
        } else {
            $result = array();

            // get document record
            if (empty($docid)) {
                $docid = $this->_core->documentIdentifier;
                $docRow = $this->_core->documentObject;
            } else {
                $docRow = $this->getDocument($docid, '*', $published);
                if (!$docRow)
                    return false;
            }

            // get user defined template variables
            $fields = ($fields == "") ? "tv.*" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $fields)));
            $sort = ($sort == "") ? "" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $sort)));
            if ($idnames == "*")
                $query = "tv.id<>0";
            else
                $query = (is_numeric($idnames[0]) ? "tv.id" : "tv.name") . " IN ('" . implode("','", $idnames) . "')";
            $sql = "SELECT $fields, IF(tvc.value!='',tvc.value,tv.default_text) as value ";
            $sql .= "FROM " . $this->_core->getTableName('BTv') . " tv ";
            $sql .= "INNER JOIN " . $this->_core->getTableName('BTvTemplate') . " tvtpl ON tvtpl.tmplvarid = tv.id ";
            $sql .= "LEFT JOIN " . $this->_core->getTableName('BTvValue') . " tvc ON tvc.tmplvarid=tv.id ";
            $sql .= "WHERE " . $query . " AND tvc.contentid = '" . $docid . "' AND tvtpl.templateid = " . $docRow['template'];
            if ($sort)
                $sql .= " ORDER BY $sort $dir ";
            $rs = $this->_core->db->query($sql);
            for ($i = 0; $i < @ $this->_core->db->getRecordCount($rs); $i++) {
                array_push($result, @ $this->_core->db->getRow($rs));
            }

            // get default/built-in template variables
            ksort($docRow);
            foreach ($docRow as $key => $value) {
                if ($idnames == "*" || in_array($key, $idnames))
                    array_push($result, array(
                        "name" => $key,
                        "value" => $value
                    ));
            }

            return $result;
        }
    }

    /**
     * Get the TVs of a document's children. Returns an array where each element represents one child doc.
     *
     * Ignores deleted children. Gets all children - there is no where clause available.
     *
     * @param int $parentid The parent docid
     *                 Default: 0 (site root)
     * @param array $tvidnames . Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only)
     *                                               or the TV names (array elements should be names only)
     *                      Default: Empty array
     * @param int $published Whether published or unpublished documents are in the result
     *                      Default: 1
     * @param string $docsort How to sort the result array (field)
     *                      Default: menuindex
     * @param string $docsortdir How to sort the result array (direction)
     *                      Default: ASC
     * @param string $tvfields Fields to fetch from site_tmplvars, default '*'
     *                      Default: *
     * @param string $tvsort How to sort each element of the result array i.e. how to sort the TVs (field)
     *                      Default: rank
     * @param string $tvsortdir How to sort each element of the result array i.e. how to sort the TVs (direction)
     *                      Default: ASC
     * @return boolean|array
     */
    public function getDocumentChildrenTVars($parentid = 0, $tvidnames = array(), $published = 1, $docsort = "menuindex", $docsortdir = "ASC", $tvfields = "*", $tvsort = "rank", $tvsortdir = "ASC")
    {
        $docs = $this->getDocumentChildren($parentid, $published, 0, '*', '', $docsort, $docsortdir);
        if (!$docs)
            return false;
        else {
            $result = array();
            // get user defined template variables
            $fields = ($tvfields == "") ? "tv.*" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $tvfields)));
            $tvsort = ($tvsort == "") ? "" : 'tv.' . implode(',tv.', preg_replace("/^\s/i", "", explode(',', $tvsort)));
            if ($tvidnames == "*")
                $query = "tv.id<>0";
            else
                $query = (is_numeric($tvidnames[0]) ? "tv.id" : "tv.name") . " IN ('" . implode("','", $tvidnames) . "')";
            if ($docgrp = $this->_inj['user']->getUserDocGroups())
                $docgrp = implode(",", $docgrp);

            $docCount = count($docs);
            for ($i = 0; $i < $docCount; $i++) {

                $tvs = array();
                $docRow = $docs[$i];
                $docid = $docRow['id'];

                $sql = "SELECT $fields, IF(tvc.value!='',tvc.value,tv.default_text) as value ";
                $sql .= "FROM " . $this->_core->getTableName('BTv') . " tv ";
                $sql .= "INNER JOIN " . $this->_core->getTableName('BTvTemplate') . " tvtpl ON tvtpl.tmplvarid = tv.id ";
                $sql .= "LEFT JOIN " . $this->_core->getTableName('BTvValue') . " tvc ON tvc.tmplvarid=tv.id ";
                $sql .= "WHERE " . $query . " AND tvc.contentid = '" . $docid . "' AND tvtpl.templateid = " . $docRow['template'];
                if ($tvsort)
                    $sql .= " ORDER BY $tvsort $tvsortdir ";
                $rs = $this->_core->db->query($sql);
                $limit = @ $this->_core->db->getRecordCount($rs);
                for ($x = 0; $x < $limit; $x++) {
                    array_push($tvs, @ $this->_core->db->getRow($rs));
                }

                // get default/built-in template variables
                ksort($docRow);
                foreach ($docRow as $key => $value) {
                    if ($tvidnames == "*" || in_array($key, $tvidnames))
                        array_push($tvs, array(
                            "name" => $key,
                            "value" => $value
                        ));
                }

                if (count($tvs))
                    array_push($result, $tvs);
            }
            return $result;
        }
    }

    /**
     * Get the TVs that belong to a template
     *
     * @param int $template
     * @return array
     */
    function getTemplateTVs($template)
    {
        $rs = $this->_inj['db']->query('SELECT tv.*
                                    FROM ' . $this->_inj['core']->getTableName('BTv') . ' tv
                                    INNER JOIN ' . $this->_inj['core']->getTableName('BTvTemplate') . ' tvtpl ON tvtpl.tmplvarid = tv.id
                                    WHERE tvtpl.templateid = ' . $template);
        return $this->_inj['db']->makeArray($rs);
    }

    /**
     * Get the TV outputs of a document's children.
     *
     * Returns an array where each element represents one child doc and contains the result from getTemplateVarOutput()
     *
     * Ignores deleted children. Gets all children - there is no where clause available.
     *
     * @param int $parentid The parent docid
     *                        Default: 0 (site root)
     * @param array $tvidnames . Which TVs to fetch. In the form expected by getTemplateVarOutput().
     *                        Default: Empty array
     * @param int $published Whether published or unpublished documents are in the result
     *                        Default: 1
     * @param string $docsort How to sort the result array (field)
     *                        Default: menuindex
     * @param string $docsortdir How to sort the result array (direction)
     *                        Default: ASC
     * @return boolean|array
     */
    public function getDocumentChildrenTVarOutput($parentid = 0, $tvidnames = array(), $published = 1, $docsort = "menuindex", $docsortdir = "ASC")
    {
        $docs = $this->getDocumentChildren($parentid, $published, 0, '*', '', $docsort, $docsortdir);
        if (!$docs)
            return false;
        else {
            $result = array();
            for ($i = 0; $i < count($docs); $i++) {
                $tvs = $this->getTemplateVarOutput($tvidnames, $docs[$i]["id"], $published);
                if ($tvs)
                    $result[$docs[$i]['id']] = $tvs; // Use docid as key - netnoise 2006/08/14
            }
            return $result;
        }
    }

    /**
     * Returns an associative array containing TV rendered output values.
     *
     * @param array $idnames Which TVs to fetch - Can relate to the TV ids in the db (array elements should be numeric only)
     *                                               or the TV names (array elements should be names only)
     *                        Default: Empty array
     * @param int $docid Docid. Defaults to empty string which indicates the current document.
     * @param int $published Whether published or unpublished documents are in the result
     *                        Default: 1
     * @param string $sep
     * @return boolean|array
     */
    public function getTemplateVarOutput($idnames = array(), $docid = 0, $published = 1, $sep = '')
    {
        if (count($idnames) == 0) {
            return false;
        } else {
            $output = array();
            $vars = ($idnames == '*' || is_array($idnames)) ? $idnames : array($idnames);
            $docid = intval($docid) ? intval($docid) : $this->documentIdentifier;
            $result = $this->getTemplateVars($vars, "*", $docid, $published, "", "", $sep); // remove sort for speed
            if ($result == false)
                return false;
            else {
                $baspath = BOLMER_MANAGER_PATH . "includes";
                include_once $baspath . "/tmplvars.format.inc.php";
                include_once $baspath . "/tmplvars.commands.inc.php";
                for ($i = 0; $i < count($result); $i++) {
                    $row = $result[$i];
                    if (!$row['id'])
                        $output[$row['name']] = $row['value'];
                    else    $output[$row['name']] = getTVDisplayFormat($row['name'], $row['value'], $row['display'], $row['display_params'], $row['type'], $docid, $sep);
                }
                return $output;
            }
        }
    }

    /**
     * Format alias to be URL-safe. Strip invalid characters.
     *
     * @param string $alias Alias to be formatted
     * @return string Safe alias
     */
    public function stripAlias($alias)
    {
        // let add-ons overwrite the default behavior
        $results = $this->_core->invokeEvent('OnStripAlias', array('alias' => $alias));
        if (!empty($results)) {
            // if multiple plugins are registered, only the last one is used
            return end($results);
        } else {
            // default behavior: strip invalid characters and replace spaces with dashes.
            $alias = strip_tags($alias); // strip HTML
            $alias = preg_replace('/[^\.A-Za-z0-9 _-]/', '', $alias); // strip non-alphanumeric characters
            $alias = preg_replace('/\s+/', '-', $alias); // convert white-space to dash
            $alias = preg_replace('/-+/', '-', $alias); // convert multiple dashes to one
            $alias = trim($alias, '-'); // trim excess
            return $alias;
        }
    }

    /**
     * Поиск ID документа по его псевдониму
     *
     * @param string $alias псевдоним документа
     * @return bool|int
     */
    public function getIdFromAlias($alias)
    {
        $children = array();

        $tbl_site_content = $this->_core->getTableName('BDoc');
        if ($this->_core->getConfig('use_alias_path') == 1) {
            if (strpos($alias, '/') !== false) $_a = explode('/', $alias);
            else                           $_a[] = $alias;
            $id = 0;

            foreach ($_a as $alias) {
                if ($id === false) break;
                $alias = $this->_core->db->escape($alias);
                $rs = $this->_core->db->select('id', $tbl_site_content, "deleted=0 and parent='{$id}' and alias='{$alias}'");
                if ($this->_core->db->getRecordCount($rs) == 0) $rs = $this->_core->db->select('id', $tbl_site_content, "deleted=0 and parent='{$id}' and id='{$alias}'");
                $row = $this->_core->db->getRow($rs);

                if ($row) $id = $row['id'];
                else     $id = false;
            }
        } else {
            $rs = $this->_core->db->select('id', $tbl_site_content, "deleted=0 and alias='{$alias}'", 'parent, menuindex');
            $row = $this->_core->db->getRow($rs);

            if ($row) $id = $row['id'];
            else     $id = false;
        }
        return $id;
    }

    /**
     * Returns allowed child templates for a document
     *
     * @param $docid
     * @return array
     */
    function getDocumentAllowedChildTemplates($docid)
    {
        $rs = $this->_core->db->query('SELECT te.restrict_children, te.allowed_child_templates
                                    FROM ' . $this->_core->getTableName('BDoc') . ' sc, ' . $this->_core->getTableName('BTemplate') . ' te
                                    WHERE sc.template = te.id
                                    AND sc.id = ' . $docid);
        $row = $this->_core->db->getRow($rs);

        if ($row['restrict_children']) {
            $allowed_child_templates = trim($row['allowed_child_templates']);
            return $allowed_child_templates ? explode(',', $allowed_child_templates) : array();
        } else {
            $rs2 = $this->_core->db->select('id', $this->_core->getTableName('BTemplate'));
            return $this->_core->db->getColumn('id', $rs2);
        }
    }
}