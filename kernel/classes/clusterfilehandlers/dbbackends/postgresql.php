<?php
/**
 * File containing the eZDBFileHandlerPostgresqlBackend class.
 *
 * @copyright Copyright (C) 1999-2010 eZ Systems AS. All rights reserved.
 * @license http://ez.no/licenses/gnu_gpl GNU GPL v2
 * @version //autogentag//
 * @package Cluster
 */

/**
 * This class allows DB based clustering using PostgreSQL
 * @package Cluster
 */
if ( !defined( 'TABLE_METADATA' ) )
    define( 'TABLE_METADATA', 'ezdbfile' );

class eZDBFileHandlerPostgresqlBackend implements eZDBFileHandlerBackend
{
    /// @todo check when parent does use $newLink param
    function _connect( /*$newLink = false*/ )
    {
        $siteINI = eZINI::instance( 'site.ini' );
        if ( self::$dbparams === null )
        {
            $fileINI = eZINI::instance( 'file.ini' );

            self::$dbparams['host']   = $fileINI->variable( 'ClusteringSettings', 'DBHost' );
            self::$dbparams['port']   = $fileINI->variable( 'ClusteringSettings', 'DBPort' );
            self::$dbparams['socket'] = $fileINI->variable( 'ClusteringSettings', 'DBSocket' );
            self::$dbparams['dbname'] = $fileINI->variable( 'ClusteringSettings', 'DBName' );
            self::$dbparams['user']   = $fileINI->variable( 'ClusteringSettings', 'DBUser' );
            self::$dbparams['pass']   = $fileINI->variable( 'ClusteringSettings', 'DBPassword' );

            self::$dbparams['max_connect_tries']        = $fileINI->variable( 'ClusteringSettings', 'DBConnectRetries' );
            self::$dbparams['max_execute_tries']        = $fileINI->variable( 'ClusteringSettings', 'DBExecuteRetries' );
            self::$dbparams['sql_output']               = $siteINI->variable( 'DatabaseSettings',   'SQLOutput' ) == 'enabled';
            self::$dbparams['cache_generation_timeout'] = $siteINI->variable( 'ContentSettings',    'CacheGenerationTimeout' );
        }

        $tries = 0;
        $connectString  = sprintf( 'pgsql:host=%s;dbname=%s;port=%s', self::$dbparams['host'], self::$dbparams['dbname'], self::$dbparams['port'] );
        while ( $tries < self::$dbparams['max_connect_tries'] )
        {
            try {
                $this->db = new PDO( $connectString, self::$dbparams['user'], self::$dbparams['pass'] );
            } catch ( PDOException $e ) {
                eZDebug::writeError( $e->getMessage() );
                ++$tries;
                continue;
            }
            break;
        }
        if ( !( $this->db instanceof PDO ) )
            return $this->_die( "Unable to connect to storage server" );

        $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->db->exec( 'SET NAMES \'utf8\'' );
    }

    function _copy( $srcFilePath, $dstFilePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_copy($srcFilePath, $dstFilePath)";
        else
            $fname = "_copy($srcFilePath, $dstFilePath)";

        // fetch source file metadata
        $metaData = $this->_fetchMetadata( $srcFilePath, $fname );
        if ( !$metaData ) // if source file does not exist then do nothing.
            return false;
        return $this->_protect( array( $this, "_copyInner" ), $fname,
                                $srcFilePath, $dstFilePath, $fname, $metaData );
    }

    function _copyInner( $srcFilePath, $dstFilePath, $fname, $metaData )
    {
        $this->_delete( $dstFilePath, true, $fname );

        $datatype        = $metaData['datatype'];
        $filePathHash    = md5( $dstFilePath );
        $scope           = $metaData['scope'];
        $contentLength   = $metaData['size'];
        $fileMTime       = $metaData['mtime'];

        // Copy file metadata.
        if ( $this->_insertUpdate( array( 'datatype'=> $datatype,
                                          'name' => $dstFilePath,
                                          'name_hash' => $filePathHash,
                                          'scope' => $scope,
                                          'size' => $contentLength,
                                          'mtime' => $fileMTime,
                                          'expired' => ($fileMTime < 0) ? 1 : 0 ),
                                   array( 'datatype', 'scope', 'size', 'mtime', 'expired' ),
                                   $fname ) === false )
        {
            return $this->_fail( $srcFilePath, "Failed to insert file metadata on copying." );
        }

        // Copy file data.

        /*$sql = "SELECT filedata, offset FROM " . TABLE_DATA . " WHERE name_hash=" . $this->_quote( md5 ( $srcFilePath ) ) . " ORDER BY offset";
        if ( !$res = $this->_query( $sql, $fname ) )
        {
            eZDebug::writeError( "Failed to fetch source file '$srcFilePath' data on copying.", __METHOD__ );
            return false;
        }

        $offset = 0;
        while ( $row = pg_fetch_row( $res ) )
        {
            // make the data mysql insert safe.
            $binarydata = $row[0];
            $expectedOffset = $row[1];
            if ( $expectedOffset != $offset )
            {
                eZDebug::writeError( "The fetched offset value '$expectedOffset' does not match the computed one for the file '$srcFilePath', aborting copy.",
                                     __METHOD__ );
                return false;
            }

            if ( $this->_insertUpdate( TABLE_DATA,
                                       array( 'name_hash' => $filePathHash,
                                              'offset' => $offset,
                                              'filedata' => $binarydata ),
                                       "filedata=VALUES(filedata)",
                                       $fname ) === false )
            {
                return $this->_fail( "Failed to insert data row while copying file." );
            }
            $offset += strlen( $binarydata );
        }
        pg_free_result( $res );
        if ( $offset != $contentLength )
        {
            eZDebug::writeError( "The size of the fetched data '$offset' does not match the expected size '$contentLength' for the file '$srcFilePath', aborting copy.",
                                 __METHOD__ );
            return false;
        }

        // Get rid of unused/old offset data.
        $result = $this->_cleanupFiledata( $dstFilePath, $contentLength, $fname );
        if ( $this->_isFailure( $result ) )
            return $result;*/

        return true;
    }

    /*!
     Purges meta-data and file-data for the file entry named $filePath from the database.
     */
    function _purge( $filePath, $onlyExpired = false, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purge($filePath)";
        else
            $fname = "_purge($filePath)";
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE name_hash=" . $this->_quote( md5( $filePath ) );
        if ( $expiry !== false )
            $sql .= " AND mtime < " . (int)$expiry;
        elseif ( $onlyExpired )
            $sql .= " AND expired = 1";
        if ( !$this->_query( $sql, $fname ) )
            return $this->_fail( "Purging file metadata for $filePath failed" );
        return true;
    }

    /*!
     Purges meta-data and file-data for the matching files.
     Matching is done by passing the string $like to the LIKE statement in the SQL.
     */
    function _purgeByLike( $like, $onlyExpired = false, $limit = 50, $expiry = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_purgeByLike($like, $onlyExpired)";
        else
            $fname = "_purgeByLike($like, $onlyExpired)";
        $sql = "DELETE FROM ezdbfile WHERE ";

        $where = array();
        if ( !$limit )
            $where[] = "name LIKE " . $this->_quote( $like );
        if ( $expiry !== false )
            $where[] = "mtime < " . (int)$expiry;
        elseif ( $onlyExpired )
            $where[] = "expired = 1";
        if ( $limit )
            $where[] = "name_hash = any( array( SELECT name_hash FROM ezdbfile WHERE name LIKE " . $this->_quote( $like ) . " LIMIT $limit ) )";

        $sql .= implode( ' AND ', $where );
        try {
            $affectedRows = $this->db->exec( $sql );
        } catch( Exception $e ) {
            return $this->_fail( "Purging file metadata by like statement $like failed" );;
        }
        return $affectedRows;
    }

    function _delete( $filePath, $insideOfTransaction = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_delete($filePath)";
        else
            $fname = "_delete($filePath)";
        if ( $insideOfTransaction )
        {
            $res = $this->_deleteInner( $filePath, $fname );
            if ( !$res || $res instanceof eZMySQLBackendError )
            {
                $this->_handleErrorType( $res );
            }
        }
        else
            return $this->_protect( array( $this, '_deleteInner' ), $fname,
                                    $filePath, $insideOfTransaction, $fname );
    }

    function _deleteInner( $filePath, $fname )
    {
        $sql = "UPDATE ezdbfile SET mtime=-ABS(mtime), expired=1 WHERE name_hash=" . $this->_quote( md5( $filePath ) );
        try {
            $this->db->exec( $sql );
        } catch( PDOException $e ) {
            return $this->_fail( "Deleting file $filePath failed", $e->getMessage() );
        }
        return true;
    }

    function _deleteByLike( $like, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByLike($like)";
        else
            $fname = "_deleteByLike($like)";
        return $this->_protect( array( $this, '_deleteByLikeInner' ), $fname,
                                $like, $fname );
    }

    function _deleteByLikeInner( $like, $fname )
    {
        $sql = "UPDATE ezdbfile SET mtime=-ABS(mtime), expired=1\nWHERE name like ". $this->_quote( $like );
        try {
            $this->db->exec( $sql );
        } catch( PDOException $e ) {
            return $this->_fail( "Failed to delete files by like: '$like'", $e->getMessage() );
        }
        return true;
    }

    function _deleteByRegex( $regex, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByRegex($regex)";
        else
            $fname = "_deleteByRegex($regex)";
        return $this->_protect( array( $this, '_deleteByRegexInner' ), $fname,
                                $regex, $fname );
    }

    function _deleteByRegexInner( $regex, $fname )
    {
        $sql = "UPDATE ezdbfile SET mtime=-ABS(mtime), expired=1\nWHERE name REGEXP " . $this->_quote( $regex );
        if ( !$res = $this->db->exec( $sql ) )
        {
            return $this->_fail( "Failed to delete files by regex: '$regex'" );
        }
        return true;
    }

    function _deleteByWildcard( $wildcard, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByWildcard($wildcard)";
        else
            $fname = "_deleteByWildcard($wildcard)";
        return $this->_protect( array( $this, '_deleteByWildcardInner' ), $fname,
                                $wildcard, $fname );
    }

    function _deleteByWildcardInner( $wildcard, $fname )
    {
        // Convert wildcard to regexp.
        $regex = '^' . pg_escape_string( $this->db, $wildcard ) . '$';

        $regex = str_replace( array( '.'  ),
                              array( '\.' ),
                              $regex );

        $regex = str_replace( array( '?', '*',  '{', '}', ',' ),
                              array( '.', '.*', '(', ')', '|' ),
                              $regex );

        $sql = "UPDATE ezdbfile SET mtime=-ABS(mtime), expired=1\nWHERE name REGEXP '$regex'";
        if ( !$res = $this->db->exec( $sql ) )
        {
            return $this->_fail( "Failed to delete files by wildcard: '$wildcard'" );
        }
        return true;
    }

    function _deleteByDirList( $dirList, $commonPath, $commonSuffix, $fname = false )
    {
        if ( $fname )
            $fname .= "::_deleteByDirList($dirList, $commonPath, $commonSuffix)";
        else
            $fname = "_deleteByDirList($dirList, $commonPath, $commonSuffix)";
        return $this->_protect( array( $this, '_deleteByDirListInner' ), $fname,
                                $dirList, $commonPath, $commonSuffix, $fname );
    }

    function _deleteByDirListInner( $dirList, $commonPath, $commonSuffix, $fname )
    {
        foreach ( $dirList as $dirItem )
        {
            /*if ( strstr( $commonPath, '/cache/content' ) !== false or strstr( $commonPath, '/cache/template-block' ) !== false )
            {
                $where = "WHERE name_trunk = '$commonPath/$dirItem/$commonSuffix'";
            }
            else
            {
            $where = "WHERE name LIKE '$commonPath/$dirItem/$commonSuffix%'";
            }*/
            $where = "WHERE name LIKE '$commonPath/$dirItem/$commonSuffix%'";
            $sql = "UPDATE ezdbfile SET mtime=-ABS(mtime), expired=1\n$where";
            if ( !$res = $this->db->exec( $sql ) )
            {
                eZDebug::writeError( "Failed to delete files in dir: '$commonPath/$dirItem/$commonSuffix%'", __METHOD__ );
            }
        }
        return true;
    }


    function _exists( $filePath, $fname = false, $ignoreExpiredFiles = true )
    {
        if ( $fname )
            $fname .= "::_exists($filePath)";
        else
            $fname = "_exists($filePath)";
        $sql = "SELECT name, mtime FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $filePath ) );
        $row = $this->_selectOneRow( $sql, $fname, "Failed to check file '$filePath' existance: ", true );
        if ( $row === false )
            return false;

        if ( $ignoreExpiredFiles )
            $rc = $row[1] >= 0;
        else
            $rc = true;

        return $rc;
    }

    function __mkdir_p( $dir )
    {
        // create parent directories
        $dirElements = explode( '/', $dir );
        if ( count( $dirElements ) == 0 )
            return true;

        $result = true;
        $currentDir = $dirElements[0];

        if ( $currentDir != '' && !file_exists( $currentDir ) && !eZDir::mkdir( $currentDir, false ))
            return false;

        for ( $i = 1; $i < count( $dirElements ); ++$i )
        {
            $dirElement = $dirElements[$i];
            if ( strlen( $dirElement ) == 0 )
                continue;

            $currentDir .= '/' . $dirElement;

            if ( !file_exists( $currentDir ) && !eZDir::mkdir( $currentDir, false ) )
                return false;

            $result = true;
        }

        return $result;
    }

    /**
    * Fetches the file $filePath from the database, saving it locally with its
    * original name, or $uniqueName if given
    *
    * @param string $filePath
    * @param string $uniqueName
    * @return the file physical path, or false if fetch failed
    **/
    function _fetch( $filePath, $uniqueName = false )
    {
        $metaData = $this->_fetchMetadata( $filePath );
        if ( !$metaData )
        {
            eZDebug::writeError( "File '$filePath' does not exist while trying to fetch.", __METHOD__ );
            return false;
        }
        $contentLength = $metaData['size'];

        $contents = $this->_fetchLob( $filePath );

        if ( strlen( $contents ) != $contentLength )
        {
            eZDebug::writeError( "The size of the fetched data '$offset' does not match the expected size '$contentLength' for the file '$filePath', aborting fetch.", __METHOD__ );
            fclose( $fp );
            @unlink( $filePath );
            return false;
        }

        // create temporary file
        if ( strrpos( $filePath, '.' ) > 0 )
            $tmpFilePath = substr_replace( $filePath, getmypid().'tmp', strrpos( $filePath, '.' ), 0  );
        else
            $tmpFilePath = $filePath . '.' . getmypid().'tmp';
        $this->__mkdir_p( dirname( $tmpFilePath ) );

        if ( !( $fp = fopen( $tmpFilePath, 'wb' ) ) )
        {
            eZDebug::writeError( "Cannot write to '$tmpFilePath' while fetching file.", __METHOD__ );
            return false;
        }

        fwrite( $fp, $contents );
        fclose( $fp );

        // Make sure all data is written correctly
        clearstatcache();
        $tmpSize = filesize( $tmpFilePath );
        if ( $tmpSize != $metaData['size'] )
        {
            eZDebug::writeError( "Size ($tmpSize) of written data for file '$tmpFilePath' does not match expected size " . $metaData['size'], __METHOD__ );
            return false;
        }

        if ( ! $uniqueName === true )
        {
            eZFile::rename( $tmpFilePath, $filePath );
        }
        else
        {
            $filePath = $tmpFilePath;
        }

        return $filePath;
    }

    function _fetchContents( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchContents($filePath)";
        else
            $fname = "_fetchContents($filePath)";
        $metaData = $this->_fetchMetadata( $filePath, $fname );
        if ( !$metaData )
        {
            eZDebug::writeError( "File '$filePath' does not exist while trying to fetch its contents.", __METHOD__ );
            return false;
        }
        $contentLength = $metaData['size'];

        $contents = $this->_fetchLob( $filePath );

        return $contents;
    }

    public function _fetchLob( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchLob( $filePath )";
        else
            $fname = "_fetchLob( $filePath )";

        $this->_begin();
        $sql = "SELECT size, data FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $filePath ) );
        $row = $this->_selectOneAssoc( $sql, $fname );

        $lob = $this->db->pgsqlLOBOpen( $row['data'], 'rb' );
        $contents = stream_get_contents( $lob );
        $lob = null;

        $this->_commit();

        return $contents;
    }

    /**
     * \return file metadata, or false if the file does not exist in database.
     */
    function _fetchMetadata( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_fetchMetadata($filePath)";
        else
            $fname = "_fetchMetadata($filePath)";
        $sql = "SELECT * FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $filePath ) );
        return $this->_selectOneAssoc( $sql, $fname,
                                       "Failed to retrieve file metadata: $filePath",
                                       true );
    }

    function _linkCopy( $srcPath, $dstPath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_linkCopy($srcPath,$dstPath)";
        else
            $fname = "_linkCopy($srcPath,$dstPath)";
        return $this->_copy( $srcPath, $dstPath, $fname );
    }

    /**
     * \deprecated This function should not be used since it cannot handle reading errors.
     *             For the PHP 5 port this should be removed.
     */
    function _passThrough( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_passThrough($filePath)";
        else
            $fname = "_passThrough($filePath)";

        $metaData = $this->_fetchMetadata( $filePath, $fname );
        if ( !$metaData )
            return false;

        $sql = "SELECT filedata FROM " . TABLE_DATA . " WHERE name_hash=" . $this->md5( $filePath ) . " ORDER BY offset";
        if ( !$res = $this->_query( $sql, $fname ) )
        {
            eZDebug::writeError( "Failed to fetch file data for file '$filePath'.", __METHOD__ );
            return false;
        }

        while ( $row = pg_fetch_row( $res ) )
            echo $row[0];

        pg_free_result( $res );
        return true;
    }

    function _rename( $srcFilePath, $dstFilePath )
    {
        if ( strcmp( $srcFilePath, $dstFilePath ) == 0 )
            return;

        // fetch source file metadata
        $metaData = $this->_fetchMetadata( $srcFilePath );
        if ( !$metaData ) // if source file does not exist then do nothing.
            return false;

        // fetch target file metadata, just to make sure it doesn't exist
        $dstMetaData = $this->_fetchMetadata( $dstFilePath );
        if ( $dstMetaData !== false )
        {
            eZDebug::writeDebug("Attempt to move {$srcFilePath} file over an existing file", __METHOD__ );
            return false;
        }

        $this->_begin( __METHOD__ );

        // Just rename the file. The OID takes care of the rest.
        $sql = "UPDATE  ezdbfile SET name = :destinationName, name_hash = :destinationNameHash WHERE name_hash = :sourceNameHash";
        $st = $this->db->prepare( $sql );
        $st->bindValue( ':destinationName',     $dstFilePath );
        $st->bindValue( ':destinationNameHash', md5( $dstFilePath ) );
        $st->bindValue( ':sourceNameHash',      md5( $srcFilePath ) );
        $st->execute();

        $this->_commit( __METHOD__ );

        return true;
    }

    function _store( $filePath, $datatype, $scope, $fname = false )
    {
        if ( !is_readable( $filePath ) )
        {
            eZDebug::writeError( "Unable to store file '$filePath' since it is not readable.", __METHOD__ );
            return;
        }
        if ( $fname )
            $fname .= "::_store($filePath, $datatype, $scope)";
        else
            $fname = "_store($filePath, $datatype, $scope)";

        $this->_protect( array( $this, '_storeInner' ), $fname,
                         $filePath, $datatype, $scope, $fname );
    }

    function _storeInner( $filePath, $datatype, $scope, $fname )
    {
        // Insert file metadata.
        clearstatcache();
        $fileMTime = filemtime( $filePath );
        $contentLength = filesize( $filePath );
        $filePathHash = $this->_md5( $filePath );

        if ( $this->_insertUpdate( array( 'datatype' => $datatype,
                                          'name' => $filePath,
                                          'name_hash' => $filePathHash,
                                          'scope' => $scope,
                                          'size' => $contentLength,
                                          'mtime' => $fileMTime,
                                          'expired' => ($fileMTime < 0) ? 1 : 0 ),
                                   array( 'datatype', 'scope', 'size', 'mtime', 'expired' ),
                                   $fname, true, array( '_storeLobFromFile', $filePath ) ) === false )
        {
            return $this->_fail( "Failed to insert file metadata while storing. Possible race condition" );
        }

        // Get rid of unused/old offset data.
        $result = $this->_cleanupFiledata( $filePath, $contentLength, $fname );
        if ( $this->_isFailure( $result ) )
            return $result;

        return true;
    }

    function _storeContents( $filePath, $contents, $scope, $datatype, $mtime = false, $fname = false )
    {
        if ( $fname )
            $fname .= "::_storeContents($filePath, ..., $scope, $datatype)";
        else
            $fname = "_storeContents($filePath, ..., $scope, $datatype)";

        $this->_protect( array( $this, '_storeContentsInner' ), $fname,
                         $filePath, $contents, $scope, $datatype, $mtime, $fname );
    }

    function _storeContentsInner( $filePath, $contents, $scope, $datatype, $curTime, $fname )
    {
        // Insert file metadata.
        $contentLength = strlen( $contents );
        $filePathHash = md5( $filePath );
        if ( $curTime === false )
            $curTime = time();
        $insertData = array( 'datatype' => $datatype,
                             'name' => $filePath,
                             'name_hash' => $filePathHash,
                             'scope' => $scope,
                             'size' => $contentLength,
                             'mtime' => $curTime,
                             'expired' => ( $curTime < 0 ) ? 1 : 0,
                             'data' => array( 'contents' => $contents ) );
        $updateData = array( 'datatype' => $insertData['datatype'],
                             'scope' => $insertData['scope'],
                             'size' => $insertData['size'],
                             'mtime' => $insertData['mtime'],
                             'expired' => $insertData['expired'],
                             'data' => $insertData['data'] );
        $this->_insertUpdate( $insertData, $updateData, $fname );
        /*
           $this->_begin();
        $oid = pg_lo_create( $this->db );

        $insertData = array( 'datatype' => $datatype,
                             'name' => $filePath,
                             'name_hash' => $filePathHash,
                             'scope' => $scope,
                             'size' => $contentLength,
                             'mtime' => $curTime,
                             'expired' => ( $curTime < 0 ) ? 1 : 0,
                             'data' => $oid );
        $dummyValues = array();
        for( $i = 1; $i <= count( $insertData ); $i++ )
            $dummyValues[] = "\${$i}";
        $sql = "INSERT INTO ezdbfile (" . implode(', ', array_keys( $insertData ) ) . ") VALUES( " . implode( ', ', $dummyValues ) . ")";
        $result = pg_query_params( $sql, array_values( $insertData ) );

        if ( $this->_isFailure( $result ) )
            return $result;

        // insert binary data
        $handle = pg_lo_open( $this->db, $oid, 'w' );
        pg_lo_write( $handle, $contents );
        pg_lo_close( $handle );

        $this->_commit();*/

        return true;
    }

    function _getFileList( $scopes = false, $excludeScopes = false )
    {
        $query = 'SELECT name FROM ezdbfile';

        if ( is_array( $scopes ) && count( $scopes ) > 0 )
        {
            $query .= ' WHERE scope ';
            if ( $excludeScopes )
                $query .= 'NOT ';
            $query .= "IN ('" . implode( "', '", $scopes ) . "')";
        }

        $rslt = $this->_query( $query, "_getFileList( array( " . implode( ', ', $scopes ) . " ), $excludeScopes )" );
        if ( !$rslt )
        {
            eZDebug::writeDebug( 'Unable to get file list', __METHOD__ );
            return false;
        }

        $filePathList = array();
        while ( $row = pg_fetch_row( $rslt ) )
            $filePathList[] = $row[0];

        pg_free_result( $rslt );
        return $filePathList;
    }

//////////////////////////////////////
//         Helper methods
//////////////////////////////////////

    function _die( $msg, $sql = null )
    {
        if ( $this->db )
        {
            eZDebug::writeError( $sql, $msg );
        }
        else
        {
            /// @todo to be fixed: will this generate a warning?
            eZDebug::writeError( $sql, $msg );
        }
    }

    /**
     *Performs an insert of the given items in $array.
     *
     * @param string $table Name of table to execute insert on.
     * @param array $array Associative array with data to insert, the keys are the field names and the values will be quoted according to type.
     * @param string $fname Name of caller.
     */
    function _insert( $table, $array, $fname )
    {
        $keys = array_keys( $array );
        $query = "INSERT INTO $table (" . join( ", ", $keys ) . ") VALUES (" . $this->_sqlList( $array ) . ")";
        return $this->_query( $query, $fname );
    }

    /**
     * Performs an insert of the given items in $array, if entry specified already exists the $update SQL is executed
     * to update the entry.
     *
     * @param string $table Name of table to execute insert on.
     * @param array $insertData
     *        Associative array with data to insert, the keys are the field names and the values will be
     *        quoted according to type.
     * @param array $updateData Partial update SQL which is executed when entry exists.
     * @param string $fname Name of caller.
     * @param mixed $lobCallback
     *        Callback to be used to insert a binary file. The name of a function callable on the current object must
     *        be provided, along with one parameter, either the file path or the file data, depending on the handler.
     *        Examples: array( '_storeLobFromData', $contents ) | _array( '_storeLobFromFilePath', $filePath )
     *
     * @return int Query result
     */
    function _insertUpdate( $insertData, $updateData, $fname, $reportError = true )
    {
        $this->_begin();

        // Create the insert query
        $keys = array_keys( $insertData );
        $placeHolderValues = array_fill( 0, count( $insertData ), '?' );

        // Create a savepoint so that the transaction can cleanly be resumed if a duplicate key error occurs
        $this->db->exec( 'SAVEPOINT step1' );
        $sql = "INSERT INTO ezdbfile (" . implode(', ', $keys ) . ") VALUES( " . implode( ', ', $placeHolderValues ) . ")";
        $st = $this->db->prepare( $sql );
        $num = 1;

        // map the fields to the query
        foreach( $insertData as $field => $value )
        {
            // data with a contents index doesn't require anything else
            // with a filepath it requires a handle to be created
            if ( $field === 'data' )
            {
                $oid = $this->db->pgsqlLOBCreate();
                $oidStream = $this->db->pgsqlLOBOpen( $oid, 'w' );
                if ( isset( $value['filepath'] ) )
                {
                    $dataHandle = fopen( $value['filepath'], 'rb' );
                    stream_copy_to_stream( $dataHandle, $oidStream );
                    $dataHandle = null;

                }
                elseif ( isset( $value['contents'] ) )
                {
                    fwrite( $oidStream, $value['contents'] );
                }
                else
                {
                    throw new Exception( "Unknown value for 'data'. Only 'filepath' and 'contents' are accepted" );
                }
                $oidStream = null;

                $value = (int)$oid;
            }

            $st->bindValue( $num, $value, $this->_PDOtype( $field ) );
            $num++;
        }

        // Try to execute the insert
        try {
            $result = $st->execute();
        } catch( PDOException $e ) {
            // The insert has failed becausse of a duplicate key error => update the row instead
            if ( $e->errorInfo[0] === self::DBERROR_DUPLICATE_KEY )
            {
                $this->db->exec( 'ROLLBACK TO SAVEPOINT step1' );

                // create the query with placeholders
                $sql = "UPDATE ezdbfile SET ";
                $sqlFields = array();
                $values = array();
                foreach( $updateData as $field => $value )
                {
                    // LOB handling
                    if ( $field === 'data' )
                    {
                        $oid = $this->db->pgsqlLOBCreate();
                        $oidStream = $this->db->pgsqlLOBOpen( $oid, 'w' );
                        if ( isset( $value['filepath'] ) )
                        {
                            $dataHandle = fopen( $value['filepath'], 'rb' );
                            stream_copy_to_stream( $dataHandle, $oidStream );
                            $dataHandle = null;

                        }
                        elseif ( isset( $value['contents'] ) )
                        {
                            fwrite( $oidStream, $value['contents'] );
                        }
                        else
                        {
                            throw new Exception( "Unknown value for 'data'. Only 'filepath' and 'contents' are accepted" );
                        }
                        $oidStream = null;

                        $value = (int)$oid;
                    }

                    $sqlFields[] = "{$field} = ?";
                    $values[$field] = $value;
                }
                $sql .= implode( ', ', $sqlFields );
                $st = $this->db->prepare( $sql );

                // bind the query values
                $index = 1;
                foreach( $values as $field => $value )
                {
                    $st->bindValue( $index, $value, $this->_PDOtype( $field) );
                    $index++;
                }
                try {
                    $result = $st->execute();
                } catch( PDOException $e ) {
                }
                $this->_commit();
                return true;
            }
            else
            {
                $this->_rollback();
                eZDebug::writeError( "SQL Error: {$e->errorInfo[2]}", __METHOD__ );
                return false;
            }
        }

        if ( $this->_isFailure( $result ) )
        {
            $this->_rollback();
            return $result;
        }

        // The record has been correctly added, send the binary data
        $this->_commit();
    }

    /*!
     Formats a list of entries as an SQL list which is separated by commas.
     Each entry in the list is quoted using _quote().
     */
    function _sqlList( $array )
    {
        $text = "";
        $sep = "";
        foreach ( $array as $e )
        {
            $text .= $sep;
            $text .= $this->_quote( $e );
            $sep = ", ";
        }
        return $text;
    }

    /**
     * Common select method for doing a SELECT query which is passed in $query and fetching one row from the result.
     * If there are more than one row it will fail and exit, if 0 it returns false.
     *
     * @param string $fname The function name that started the query, should contain relevant arguments in the text.
     * @param mixed  $error Sent to _error() in case of errors
     * @param bool   $debug If true it will display the fetched row in addition to the SQL.
     *
     * @return array|false A numerical array, or false if 0 or more than 1 rows
     */
    function _selectOneRow( $query, $fname, $error = false, $debug = false )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, PDO::FETCH_NUM );
    }

    /**
     * Common select method for doing a SELECT query which is passed in $query and
     * fetching one row from the result.
     * If there are more than one row it will fail and exit, if 0 it returns false.
     * The returned row is an associative array.
     *
     * @param string $query
     * @param string $fname The function name that started the query, should contain relevant arguments in the text.
     * @param string $error Sent to _error() in case of errors
     * @param bool $debug If true it will display the fetched row in addition to the SQL.
     */
    function _selectOneAssoc( $query, $fname, $error = false, $debug = false )
    {
        return $this->_selectOne( $query, $fname, $error, $debug, PDO::FETCH_ASSOC );
    }

    /**
     * Common select method for doing a SELECT query which is passed in $query and fetching one row from the result.
     * If there are more than one row it will fail and exit, if 0 it returns false.
     *
     * @param string $fname The function name that started the query, should contain relevant arguments in the text.
     * @param mixed  $error Sent to _error() in case of errors
     * @param bool   $debug If true it will display the fetched row in addition to the SQL.
     * @param string $fetchMode Which fetch mode must be used (one of PDO::FETCH_*)
     */
    function _selectOne( $query, $fname, $error = false, $debug = false, $fetchMode )
    {
        eZDebug::accumulatorStart( 'pg_cluster_query', 'pg_cluster_total', 'PostgreSQL_Cluster_queries' );

        $res = $this->_query( $query );
        if ( !$res )
            return false;

        $rowCount = $res->rowCount();
        if ( $rowCount > 1 )
        {
            eZDebug::writeError( 'Duplicate entries found', $fname );
            eZDebug::accumulatorStop( 'pg_cluster_query' );
            return false;
        }
        elseif ( $rowCount === 0 )
        {
            eZDebug::accumulatorStop( 'pg_cluster_query' );
            return false;
        }

        $row = $res->fetch( $fetchMode );
        $res = null;
        if ( $debug )
            $query = "SQL for _selectOne:\n" . $query . "\n\nRESULT:\n" . var_export( $row, true );

        eZDebug::accumulatorStop( 'pg_cluster_query' );

        return $row;
    }

    /**
     * Starts a new transaction by executing a BEGIN call.
     * If a transaction is already started nothing is executed.
     */
    function _begin( $fname = false )
    {
        if ( $fname )
            $fname .= "::_begin";
        else
            $fname = "_begin";
        $this->transactionCount++;
        if ( $this->transactionCount == 1 )
            $this->_query( "BEGIN", $fname );
    }

    /**
     * Stops a current transaction and commits the changes by executing a COMMIT call.
     * If the current transaction is a sub-transaction nothing is executed.
     */
    function _commit( $fname = false )
    {
        if ( $fname )
            $fname .= "::_commit";
        else
            $fname = "_commit";
        $this->transactionCount--;
        if ( $this->transactionCount == 0 )
            $this->_query( "COMMIT", $fname );
    }

    /*!
     * Stops a current transaction and discards all changes by executing a ROLLBACK call.
     * If the current transaction is a sub-transaction nothing is executed.
     */
    function _rollback( $fname = false )
    {
        if ( $fname )
            $fname .= "::_rollback";
        else
            $fname = "_rollback";
        $this->transactionCount--;
        if ( $this->transactionCount == 0 )
            $this->_query( "ROLLBACK", $fname );
    }

    /*!
     Frees a previously open shared-lock by performing a rollback on the current transaction.

     Note: There is not checking to see if a lock is started, and if
           locking was done in an existing transaction nothing will happen.
     */
    function _freeSharedLock( $fname = false )
    {
        if ( $fname )
            $fname .= "::_freeSharedLock";
        else
            $fname = "_freeSharedLock";
        $this->_rollback( $fname );
    }

    /*!
     Frees a previously open exclusive-lock by commiting the current transaction.

     Note: There is not checking to see if a lock is started, and if
           locking was done in an existing transaction nothing will happen.
     */
    function _freeExclusiveLock( $fname = false )
    {
        if ( $fname )
            $fname .= "::_freeExclusiveLock";
        else
            $fname = "_freeExclusiveLock";
        $this->_commit( $fname );
    }

    /*!
     Locks the file entry for exclusive write access.

     The locking is performed by trying to insert the entry with mtime
     set to -1, which means that file is not to be used. If it exists
     the mtime will be negated to mark it as deleted. This insert/update
     procedure will perform an exclusive lock of the row (InnoDB feature).

     Note: All reads of the row must be done with LOCK IN SHARE MODE.
     */
    function _exclusiveLock( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_exclusiveLock($filePath)";
        else
            $fname = "_exclusiveLock($filePath)";
        $this->_begin( $fname );
        $data = array( 'name' => $filePath,
                       'name_hash' => md5( $filePath ),
                       'expired' => 1,
                       'mtime' => -1 ); // -1 is used to reserve this entry.
        $tries = 0;
        $maxTries = self::$dbparams['max_execute_tries'];
        while ( $tries < $maxTries )
        {
            $this->_insertUpdate( $data,
                                  "mtime=-ABS(mtime), expired=1",
                                  $fname,
                                  false ); // turn off error reporting
            $errno = mysqli_errno( $this->db );
            if ( $errno == 1205 || // Error: 1205 SQLSTATE: HY000 (ER_LOCK_WAIT_TIMEOUT)
                 $errno == 1213 )  // Error: 1213 SQLSTATE: 40001 (ER_LOCK_DEADLOCK)
            {
                $tries++;
                continue;
            }
            else if ( $errno == 0 )
            {
                return true;
            }
            break;
        }
        return $this->_fail( "Failed to perform exclusive lock on file $filePath" );
    }

    /**
    * Uses a secondary database connection to check outside the transaction scope
    * if a file has been generated during the current process execution
    * @param string $filePath
    * @param int $expiry
    * @param int $curtime
    * @param int $ttl
    * @param string $fname
    * @return bool false if the file exists and is not expired, true otherwise
    **/
    function _verifyExclusiveLock( $filePath, $expiry, $curtime, $ttl, $fname = false )
    {
        // we need to create a new backend connection in order to be outside the
        // current transaction scope
        if ( $this->backendVerify === null )
        {
            $backendclass = get_class( $this );
            $this->backendVerify = new $backendclass( $filePath );
            $this->backendVerify->_connect( true );
        }

        // we then check the file metadata in this scope to see if it was created
        // in between
        $metaData = $this->backendVerify->_fetchMetadata( $filePath );
        if ( $metaData !== false )
        {
            if ( !eZDBFileHandler::isFileExpired( $filePath, $metaData['mtime'], max( $curtime, $expiry ), $curtime, $ttl ) )
            {
                eZDebugSetting::writeDebug( 'kernel-clustering', "DBFile '$filePath' is valid and not expired", __METHOD__ );
                return false;
            }
        }
        return true;
    }

    function _sharedLock( $filePath, $fname = false )
    {
        if ( $fname )
            $fname .= "::_sharedLock($filePath)";
        else
            $fname = "_sharedLock($filePath)";
        if ( $this->transactionCount == 0 )
            $this->_begin( $fname );
        $tries = 0;
        $maxTries = self::$dbparams['max_execute_tries'];
        while ( $tries < $maxTries )
        {
            $res = $this->_query( "SELECT * FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $filePath ) ) . " LOCK IN SHARE MODE", $fname, false ); // turn off error reporting
            $errno = mysqli_errno( $this->db );
            if ( $errno == 1205 || // Error: 1205 SQLSTATE: HY000 (ER_LOCK_WAIT_TIMEOUT)
                 $errno == 1213 )  // Error: 1213 SQLSTATE: 40001 (ER_LOCK_DEADLOCK)
            {
                $tries++;
                continue;
            }
            break;
        }
        if ( !$res )
            return $this->_fail( "Failed to perform shared lock on file $filePath" );
        return pg_fetch_assoc( $res );
    }

    /*!
     Protects a custom function with SQL queries in a database transaction,
     if the function reports an error the transaction is ROLLBACKed.

     The first argument to the _protect() is the callback and the second is the name of the function (for query reporting). The remainder of arguments are sent to the callback.

     A return value of false from the callback is considered a failure, any other value is returned from _protect(). For extended error handling call _fail() and return the value.
     */
    function _protect()
    {
        $args = func_get_args();
        $callback = array_shift( $args );
        $fname    = array_shift( $args );

        $maxTries = self::$dbparams['max_execute_tries'];
        $tries = 0;
        while ( $tries < $maxTries )
        {
            $this->_begin( $fname );

            $result = call_user_func_array( $callback, $args );

            $errno = pg_result_error_field( $result, PGSQL_DIAG_SQLSTATE );
            if ( $errno == 1205 || // Error: 1205 SQLSTATE: HY000 (ER_LOCK_WAIT_TIMEOUT)
                 $errno == 1213 )  // Error: 1213 SQLSTATE: 40001 (ER_LOCK_DEADLOCK)
            {
                $tries++;
                $this->_rollback( $fname );
                continue;
            }

            if ( $result === false )
            {
                $this->_rollback( $fname );
                return false;
            }
            elseif ( $result instanceof eZMySQLBackendError )
            {
                eZDebug::writeError( $result->errorValue, $result->errorText );
                $this->_rollback( $fname );
                return false;
            }

            break; // All is good, so break out of loop
        }

        $this->_commit( $fname );
        return $result;
    }

    function _handleErrorType( $res )
    {
        if ( $res === false )
        {
            eZDebug::writeError( "SQL failed" );
        }
        elseif ( $res instanceof eZMySQLBackendError )
        {
            eZDebug::writeError( $res->errorValue, $res->errorText );
        }
    }

    /*!
     Checks if $result is a failure type and returns true if so, false otherwise.

     A failure is either the value false or an error object of type eZMySQLBackendError.
     */
    function _isFailure( $result )
    {
        if ( $result === false || ( $result instanceof eZMySQLBackendError ) )
        {
            return true;
        }
        return false;
    }

    /*!
     Helper method for removing leftover file data rows for the file path $filePath.
     Note: This should be run after insert/updating filedata entries.

     Entries which are after $contentLength or which have different chunk offset than
     the defined chunk_size in $dbparams will be removed.

     \param $filePath The file path which was inserted/updated
     \param $contentLength The length of the file data
     \parma $fname Name of the function caller
     */
    function _cleanupFiledata( $filePath, $contentLength, $fname )
    {
        /*$chunkSize = self::$dbparams['chunk_size'];
        $sql = "DELETE FROM " . TABLE_DATA . " WHERE name_hash = " . $this->_md5( $filePath ) . " AND (offset % $chunkSize != 0 OR offset > $contentLength)";
        if ( !$this->_query( $sql, $fname ) )
            return $this->_fail( "Failed to remove old file data." );*/

        return true;
    }

    /*!
     Creates an error object which can be read by some backend functions.

     \param $value The value which is sent to the debug system.
     \param $text The text/header for the value.
     */
    function _fail( $value, $text = false )
    {
        $value .= "\n" . pg_last_error( $this->db );
        return new eZMySQLBackendError( $value, $text );
    }

    /**
     * Performs mysql query and returns mysql result.
     * Times the sql execution, adds accumulator timings and reports SQL to debug.
     *
     * @param string $query
     * @param string $fname The function name that started the query, should contain relevant arguments in the text.
     * @param bool $reportError
     *
     * @return PDOStatement
     */
    function _query( $query, $fname = false, $reportError = true )
    {
        $time = microtime( true );

        eZDebug::accumulatorStart( 'pgsql_cluster_query', 'pgsql_cluster_total', 'PostgreSQL_cluster_queries' );
        try {
            $res = $this->db->query( $query );
        } catch( PDOException $e ) {
            if ( $reportError )
                $this->_error( $query, $fname, $e->getMessage() );
            return false;
        }
        eZDebug::accumulatorStop( 'pg_cluster_query' );

        $this->_report( $query, $fname, microtime( true ) - $time, $res->rowCount() );

        return $res;
    }

    /*!
     Make sure that $value is escaped and qouted according to type and returned as a string.
     The returned value can directly be put into SQLs.
     */
    function _quote( $value )
    {
        if ( $value === null )
            return 'NULL';
        elseif ( is_integer( $value ) )
            return $value;
        else
            return $this->db->quote( $value );
    }

    /**
     * Returns the md5 sum for a name hash
     * @todo This function really isn't that useful now that it no longer quotes...
     */
    function _md5( $value )
    {
        return md5( $value );
    }

    /*!
     Prints error message $error to debug system.

     \param $query The query that was attempted, will be printed if $error is \c false
     \param $fname The function name that started the query, should contain relevant arguments in the text.
     \param $error The error message, if this is an array the first element is the value to dump and the second the error header (for eZDebug::writeNotice). If this is \c false a generic message is shown.
     */
    function _error( $query, $fname, $error = "Failed to execute SQL for function:" )
    {
        if ( $error === false )
        {
            $error = "Failed to execute SQL for function:";
        }
        else if ( is_array( $error ) )
        {
            $fname = $error[1];
            $error = $error[0];
        }

        eZDebug::writeError( $error );
    }

    /**
    * Report SQL $query to debug system.
    *
    * @param string $fname The function name that started the query, should contain relevant arguments in the text.
    * @param int    $timeTaken Number of seconds the query + related operations took (as float).
    * @param int $numRows Number of affected rows.
    **/
    function _report( $query, $fname, $timeTaken, $numRows = false )
    {
        if ( !self::$dbparams['sql_output'] )
            return;

        $rowText = '';
        if ( $numRows !== false )
            $rowText = "$numRows rows, ";
        static $numQueries = 0;
        if ( strlen( $fname ) == 0 )
            $fname = "_query";
        $backgroundClass = ($this->transactionCount > 0  ? "debugtransaction transactionlevel-$this->transactionCount" : "");
        eZDebug::writeNotice( "$query", "cluster::postgres::{$fname}[{$rowText}" . number_format( $timeTaken, 3 ) . " ms] query number per page:" . $numQueries++, $backgroundClass );
    }

    /**
    * Attempts to begin cache generation by creating a new file named as the
    * given filepath, suffixed with .generating. If the file already exists,
    * insertion is not performed and false is returned (means that the file
    * is already being generated)
    * @param string $filePath
    * @return array array with 2 indexes: 'result', containing either ok or ko,
    *         and another index that depends on the result:
    *         - if result == 'ok', the 'mtime' index contains the generating
    *           file's mtime
    *         - if result == 'ko', the 'remaining' index contains the remaining
    *           generation time (time until timeout) in seconds
    **/
    function _startCacheGeneration( $filePath, $generatingFilePath )
    {
        $fname = "_startCacheGeneration( {$filePath} )";

        $nameHash = md5( $generatingFilePath );
        $mtime = time();

        $insertData = array( 'name' => $this->_quote( $generatingFilePath ),
                             'name_hash' => $this->_quote( $nameHash ),
                             'scope' => $this->_quote( '' ),
                             'datatype' => $this->_quote( '' ),
                             'mtime' => $this->_quote( $mtime ),
                             'expired' => 0 );
        $query = 'INSERT INTO ezdbfile ( '. implode(', ', array_keys( $insertData ) ) . ' ) ' .
                 "VALUES(" . implode( ', ', $insertData ) . ")";

        try {
            $result = $this->db->exec( $query );
        } catch( PDOException $e ) {
            $errno = $e->errorInfo[0];

            // unexpected error
            if ( $errno != self::DBERROR_DUPLICATE_KEY )
            {
                eZDebug::writeError( "Unexpected error #$errno when trying to start cache generation on $filePath ({$e->errorInfo[2]})", __METHOD__ );
                eZDebug::writeDebug( $query, '$query' );

                // @todo Make this an actual error, maybe an exception
                return array( 'res' => 'ko' );
            }
            // expected duplicate key error
            else
            {
                // generation timout check
                $query = "SELECT mtime FROM ezdbfile WHERE name_hash = " . $this->_quote( $nameHash );
                $row = $this->_selectOneRow( $query, $fname, false, false );

                // file has been renamed, i.e it is no longer a .generating file
                if( $row === false )
                    return array( 'result' => 'ok', 'mtime' => $mtime );

                $remainingGenerationTime = $this->remainingCacheGenerationTime( $row );
                if ( $remainingGenerationTime < 0 )
                {
                    $previousMTime = $row[0];

                    eZDebugSetting::writeDebug( 'kernel-clustering', "$filePath generation has timedout (timeout=".self::$dbparams['cache_generation_timeout']."), taking over", __METHOD__ );
                    $updateQuery = "UPDATE ezdbfile SET mtime = :mtime WHERE name_hash = :nameHash AND mtime = :previousMtime";
                    $st = $this->db->prepare( $updateQuery );
                    $st->bindValue( ':mtime', $mtime );
                    $st->bindValue( ':nameHash', $nameHash );
                    $st->bindValue( ':previousMtime', $previousMTime );

                    // we run the query manually since the default _query won't
                    // report affected rows
                    $result = $st->execute();
                    if ( ( $result !== false ) && ( $st->rowCount() === 1  ) )
                    {
                        return array( 'result' => 'ok', 'mtime' => $mtime );
                    }
                    else
                    {
                        // @todo This would require an actual error handling
                        $error = $st->errorInfo();
                        eZDebug::writeError( "An error occured taking over timedout generating cache file $generatingFilePath ({$error[2]})", __METHOD__ );
                        return array( 'result' => 'error' );
                    }
                }
                else
                {
                    return array( 'result' => 'ko', 'remaining' => $remainingGenerationTime );
                }
            }
        }
        return array( 'result' => 'ok', 'mtime' => $mtime );
    }

    /**
    * Ends the cache generation for the current file: moves the (meta)data for
    * the .generating file to the actual file, and removed the .generating
    * @param string $filePath
    * @return bool
    **/
    function _endCacheGeneration( $filePath, $generatingFilePath, $rename )
    {
        $fname = "_endCacheGeneration( $filePath )";

        eZDebugSetting::writeDebug( 'kernel-clustering', $filePath, __METHOD__ );

        // if no rename is asked, the .generating file is just removed
        if ( $rename === false )
        {
            if ( !$this->db->exec( "DELETE FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $generatingFilePath ) ) ) )
            {
                eZDebug::writeError( "Failed removing metadata entry for '$generatingFilePath'", $fname );
                return false;
            }
            else
            {
                return true;
            }
        }
        else
        {
            $this->_begin( $fname );

            // both files are locked for update, and generating metadata are read
            if ( !$res = $this->_query( "SELECT * FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $generatingFilePath ) ) . " FOR UPDATE", $fname, true ) )
            {
                $this->_rollback( $fname );
                return false;
            }
            $generatingMetaData = $res->fetch( PDO::FETCH_ASSOC );
            $res->closeCursor();
            $res = null;

            // Lock target row
            $res = $this->_query( "SELECT * FROM ezdbfile WHERE name_hash=".$this->_quote( md5 ( $filePath ) )." FOR UPDATE", $fname, false );
            $originalNumRowCount = $res->rowCount();
            $res->closeCursor();
            $res = null;

            // the original file does not exist: we move the generating file
            if ( $originalNumRowCount === 0 )
            {
                $metaData = $generatingMetaData;
                $metaData['name'] = $filePath;
                $metaData['name_hash'] = md5( $filePath );
                $insertSQL = "INSERT INTO ezdbfile ( " . implode( ', ', array_keys( $metaData ) ) . " ) " .
                             "VALUES( " . $this->_sqlList( $metaData ) . ")";
                $deleteSQL = "DELETE FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $generatingFilePath ) );
                try {
                    $this->db->exec( $insertSQL );
                    $this->db->exec( $deleteSQL );
                } catch( PDOException $e ) {
                    $this->_rollback( $fname );
                    echo "Exception occured when moving generating file: " . $e->getMessage() . "\n";
                    return false;
                }

                // @todo Move data
            }
            // the original file exists: we move the generating data to this file
            // and update it
            else
            {
                $mtime = $generatingMetaData['mtime'];
                $filesize = $generatingMetaData['size'];
                $updateSQL = "UPDATE ezdbfile SET mtime = " . $this->_quote( $mtime ) . ", expired = 0, " .
                             "size = ".$this->_quote( $filesize )." WHERE name_hash=" . $this->_quote( md5( $filePath ) );
                $deleteSQL = "DELETE FROM ezdbfile WHERE name_hash=" . $this->_quote( md5( $generatingFilePath ) );
                try {
                    $this->db->exec( $updateSQL );
                    $this->db->exec( $deleteSQL );
                } catch( PDOException $e ) {
                    $this->_rollback( $fname );
                    echo "Exception occured when moving generating file: " . $e->getMessage() . "\n";
                    return false;
                }
            }

            $this->_commit( $fname );
        }

        return true;
    }

    /**
    * Checks if generation has timed out by looking for the .generating file
    * and comparing its timestamp to the one assigned when the file was created
    *
    * @param string $generatingFilePath
    * @param int    $generatingFileMtime
    *
    * @return bool true if the file didn't timeout, false otherwise
    **/
    function _checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )
    {
        $fname = "_checkCacheGenerationTimeout( $generatingFilePath, $generatingFileMtime )";
        eZDebugSetting::writeDebug( 'kernel-clustering', "Checking for timeout of '$generatingFilePath' with mtime $generatingFileMtime", $fname );

        // reporting
        eZDebug::accumulatorStart( 'pg_cluster_query', 'pg_cluster_total', 'PostgreSQL_cluster_queries' );
        $time = microtime( true );

        $nameHash = md5( $generatingFilePath );
        $newMtime = time();

        // The update query will only succeed if the mtime wasn't changed in between
        $query = "UPDATE ezdbfile SET mtime = ".$this->_quote( $newMtime ).
                 " WHERE name_hash = ".$this->_quote( $nameHash )." AND mtime = " . $this->_quote( $generatingFileMtime );
        $affectedRows = $this->db->exec( $query );
        if ( $affectedRows === false )
        {
            $this->_error( $query, $fname );
            return false;
        }

        // reporting. Manual here since we don't use _query
        $time = microtime( true ) - $time;
        $this->_report( $query, $fname, $time, $affectedRows );

        // no rows affected or row updated with the same value
        // f.e a cache-block which takes less than 1 sec to get generated
        // if a line has been updated by the same  values, mysqli_affected_rows
        // returns 0, and updates nothing, we need to extra check this,
        if( $affectedRows == 0 )
        {
            $row = $this->_selectOneRow( "SELECT mtime FROM ezdbfile WHERE name_hash = " . $this->_quote( $nameHash ) );
            if( $res && isset( $row[0] ) && $row[0] == $generatingFileMtime )
                return true;
            else
                return false;
        }
        // rows affected: mtime has changed, or row has been removed
        if ( $affectedRows == 1 )
        {
            return true;
        }
        else
        {
            eZDebugSetting::writeDebug( 'kernel-clustering', "No rows affected by query '$query', record has been modified", __METHOD__ );
            return false;
        }
    }

    /**
    * Aborts the cache generation process by removing the .generating file
    * @param string $filePath Real cache file path
    * @param string $generatingFilePath .generating cache file path
    * @return void
    **/
    function _abortCacheGeneration( $generatingFilePath )
    {
        $sql = "DELETE FROM ezdbfile WHERE name_hash = " . $this->_quote( md5( $generatingFilePath ) );
        $this->_query( $sql, "_abortCacheGeneration( '$generatingFilePath' )" );
    }

    /**
    * Returns the remaining time, in seconds, before the generating file times
    * out
    *
    * @param resource $fileRow
    *
    * @return int Remaining generation seconds. A negative value indicates a timeout.
    **/
    private function remainingCacheGenerationTime( $row )
    {
        if( !isset( $row[0] ) )
            return -1;

        return ( $row[0] + self::$dbparams['cache_generation_timeout'] ) - time();
    }

    /**
     * Returns the list of expired binary files (images + binaries)
     *
     * @param array $scopes Array of scopes to consider. At least one.
     * @param int $limit Max number of items. Set to false for unlimited.
     *
     * @return array(filepath)
     *
     * @since 4.3
     */
    public function expiredFilesList( $scopes, $limit = array( 0, 100 ) )
    {
        if ( count( $scopes ) == 0 )
            throw new ezcBaseValueException( 'scopes', $scopes, "array of scopes", "parameter" );

        $scopeString = $this->_sqlList( $scopes );
        $query = "SELECT name FROM ezdbfile WHERE expired = 1 AND scope IN( $scopeString )";
        if ( $limit !== false )
        {
            $query .= " LIMIT {$limit[0]}, {$limit[1]}";
        }
        $res = $this->_query( $query, __METHOD__ );
        $filePathList = array();
        while ( $row = pg_fetch_row( $res ) )
            $filePathList[] = $row[0];
        pg_free_result( $res );

        return $filePathList;
    }

    /**
     * Stores binary data to the database from a variable
     * @param int $loid Large Object ID as returned by lo_open()
     * @param string $contents Binary data
     *
     * @return null
     */

    public function _storeLobFromData( $loid, $contents )
    {
        $handle = pg_lo_open( $this->db, $loid, 'w' );
        pg_lo_write( $handle, $contents );
        pg_lo_close( $handle );
    }

    /**
     * Returns the PDO field type based on the field name
     *
     * @param string $field
     * @return int One of the PDO::PARAM_* constants
     */
    public function _PDOtype( $field )
    {
        switch( $field )
        {
            case 'datatype':
            case 'name_hash':
            case 'scope':
            case 'name':
                return PDO::PARAM_STR;

            case 'size':
            case 'mtime':
            case 'expired':
                return PDO::PARAM_INT;

            case 'data':
                return PDO::PARAM_INT; // We use OID and not BYTEA, and therefore will store an integer

            default:
                throw new Exception( "Unknown cluster database field '$field'" );
        }
    }

    /**
     * Stores binary data to the database from a filename
     *
     * @param int $loid Large Object ID as returned by lo_open()
     * @param string $filePath Path to the file to be stored
     *
     * @return null
     */

    public function _storeLobFromFile( $loid, $filePath )
    {
        $handle = pg_lo_open( $this->db, $loid, 'w' );
        pg_lo_write( $handle, file_get_contents( $filePath ) );
        pg_lo_close( $handle );
    }

    /**
     * @var PDO
     */
    public $db   = null;

    public $numQueries = 0;
    public $transactionCount = 0;
    public static $dbparams;
    private $backendVerify = null;

    const DBERROR_DUPLICATE_KEY = '23505';
}

?>