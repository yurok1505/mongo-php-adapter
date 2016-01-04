<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

use Alcaeus\MongoDbAdapter\Helper;
use Alcaeus\MongoDbAdapter\TypeConverter;

/**
 * Represents a database collection.
 * @link http://www.php.net/manual/en/class.mongocollection.php
 */
class MongoCollection
{
    use Helper\ReadPreference;
    use Helper\SlaveOkay;
    use Helper\WriteConcern;

    const ASCENDING = 1;
    const DESCENDING = -1;

    /**
     * @var MongoDB
     */
    public $db = NULL;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var \MongoDB\Collection
     */
    protected $collection;

    /**
     * Creates a new collection
     *
     * @link http://www.php.net/manual/en/mongocollection.construct.php
     * @param MongoDB $db Parent database.
     * @param string $name Name for this collection.
     * @throws Exception
     * @return MongoCollection
     */
    public function __construct(MongoDB $db, $name)
    {
        $this->db = $db;
        $this->name = $name;

        $this->setReadPreferenceFromArray($db->getReadPreference());
        $this->setWriteConcernFromArray($db->getWriteConcern());

        $this->createCollectionObject();
    }

    /**
     * Gets the underlying collection for this object
     *
     * @internal This part is not of the ext-mongo API and should not be used
     * @return \MongoDB\Collection
     */
    public function getCollection()
    {
        return $this->collection;
    }

    /**
     * String representation of this collection
     *
     * @link http://www.php.net/manual/en/mongocollection.--tostring.php
     * @return string Returns the full name of this collection.
     */
    public function __toString()
    {
        return (string) $this->db . '.' . $this->name;
    }

    /**
     * Gets a collection
     *
     * @link http://www.php.net/manual/en/mongocollection.get.php
     * @param string $name The next string in the collection name.
     * @return MongoCollection
     */
    public function __get($name)
    {
        // Handle w and wtimeout properties that replicate data stored in $readPreference
        if ($name === 'w' || $name === 'wtimeout') {
            return $this->getWriteConcern()[$name];
        }

        return $this->db->selectCollection($this->name . '.' . $name);
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        if ($name === 'w' || $name === 'wtimeout') {
            $this->setWriteConcernFromArray([$name => $value] + $this->getWriteConcern());
            $this->createCollectionObject();
        }
    }

    /**
     * Perform an aggregation using the aggregation framework
     *
     * @link http://www.php.net/manual/en/mongocollection.aggregate.php
     * @param array $pipeline
     * @param array $op
     * @return array
     */
    public function aggregate(array $pipeline, array $op = [])
    {
        if (! TypeConverter::isNumericArray($pipeline)) {
            $pipeline = [];
            $options = [];

            $i = 0;
            foreach (func_get_args() as $operator) {
                $i++;
                if (! is_array($operator)) {
                    trigger_error("Argument $i is not an array", E_WARNING);
                    return;
                }

                $pipeline[] = $operator;
            }
        } else {
            $options = $op;
        }

        $command = [
            'aggregate' => $this->name,
            'pipeline' => $pipeline
        ];

        $command += $options;

        return $this->db->command($command);
    }

    /**
     * Execute an aggregation pipeline command and retrieve results through a cursor
     *
     * @link http://php.net/manual/en/mongocollection.aggregatecursor.php
     * @param array $pipeline
     * @param array $options
     * @return MongoCommandCursor
     */
    public function aggregateCursor(array $pipeline, array $options = [])
    {
        // Build command manually, can't use mongo-php-library here
        $command = [
            'aggregate' => $this->name,
            'pipeline' => $pipeline
        ];

        // Convert cursor option
        if (! isset($options['cursor']) || $options['cursor'] === true || $options['cursor'] === []) {
            // Cursor option needs to be an object convert bools and empty arrays since those won't be handled by TypeConverter
            $options['cursor'] = new \stdClass;
        }

        $command += $options;

        $cursor = new MongoCommandCursor($this->db->getConnection(), (string) $this, $command);
        $cursor->setReadPreference($this->getReadPreference());

        return $cursor;
    }

    /**
     * Returns this collection's name
     *
     * @link http://www.php.net/manual/en/mongocollection.getname.php
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function setReadPreference($readPreference, $tags = null)
    {
        $result = $this->setReadPreferenceFromParameters($readPreference, $tags);
        $this->createCollectionObject();

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setWriteConcern($wstring, $wtimeout = 0)
    {
        $result = $this->setWriteConcernFromParameters($wstring, $wtimeout);
        $this->createCollectionObject();

        return $result;
    }

    /**
     * Drops this collection
     *
     * @link http://www.php.net/manual/en/mongocollection.drop.php
     * @return array Returns the database response.
     */
    public function drop()
    {
        return TypeConverter::convertObjectToLegacyArray($this->collection->drop());
    }

    /**
     * Validates this collection
     *
     * @link http://www.php.net/manual/en/mongocollection.validate.php
     * @param bool $scan_data Only validate indices, not the base collection.
     * @return array Returns the database's evaluation of this object.
     */
    public function validate($scan_data = FALSE)
    {
        $command = [
            'validate' => $this->name,
            'full'     => $scan_data,
        ];

        return $this->db->command($command);
    }

    /**
     * Inserts an array into the collection
     *
     * @link http://www.php.net/manual/en/mongocollection.insert.php
     * @param array|object $a
     * @param array $options
     * @throws MongoException if the inserted document is empty or if it contains zero-length keys. Attempting to insert an object with protected and private properties will cause a zero-length key error.
     * @throws MongoCursorException if the "w" option is set and the write fails.
     * @throws MongoCursorTimeoutException if the "w" option is set to a value greater than one and the operation takes longer than MongoCursor::$timeout milliseconds to complete. This does not kill the operation on the server, it is a client-side timeout. The operation in MongoCollection::$wtimeout is milliseconds.
     * @return bool|array Returns an array containing the status of the insertion if the "w" option is set.
     */
    public function insert($a, array $options = [])
    {
        $result = $this->collection->insertOne(
            TypeConverter::convertLegacyArrayToObject($a),
            $this->convertWriteConcernOptions($options)
        );

        if (! $result->isAcknowledged()) {
            return true;
        }

        return [
            'ok' => 1.0,
            'n' => 0,
            'err' => null,
            'errmsg' => null,
        ];
    }

    /**
     * Inserts multiple documents into this collection
     *
     * @link http://www.php.net/manual/en/mongocollection.batchinsert.php
     * @param array $a An array of arrays.
     * @param array $options Options for the inserts.
     * @throws MongoCursorException
     * @return mixed If "safe" is set, returns an associative array with the status of the inserts ("ok") and any error that may have occured ("err"). Otherwise, returns TRUE if the batch insert was successfully sent, FALSE otherwise.
     */
    public function batchInsert(array $a, array $options = [])
    {
        $result = $this->collection->insertMany(
            TypeConverter::convertLegacyArrayToObject($a),
            $this->convertWriteConcernOptions($options)
        );

        if (! $result->isAcknowledged()) {
            return true;
        }

        return [
            'connectionId' => 0,
            'n' => 0,
            'syncMillis' => 0,
            'writtenTo' => null,
            'err' => null,
            'errmsg' => null,
        ];
    }

    /**
     * Update records based on a given criteria
     *
     * @link http://www.php.net/manual/en/mongocollection.update.php
     * @param array $criteria Description of the objects to update.
     * @param array $newobj The object with which to update the matching records.
     * @param array $options
     * @throws MongoCursorException
     * @return boolean
     */
    public function update(array $criteria , array $newobj, array $options = [])
    {
        $multiple = isset($options['multiple']) ? $options['multiple'] : false;
        $method = $multiple ? 'updateMany' : 'updateOne';
        unset($options['multiple']);

        /** @var \MongoDB\UpdateResult $result */
        $result = $this->collection->$method(
            TypeConverter::convertLegacyArrayToObject($criteria),
            TypeConverter::convertLegacyArrayToObject($newobj),
            $this->convertWriteConcernOptions($options)
        );

        if (! $result->isAcknowledged()) {
            return true;
        }

        return [
            'ok' => 1.0,
            'nModified' => $result->getModifiedCount(),
            'n' => $result->getMatchedCount(),
            'err' => null,
            'errmsg' => null,
            'updatedExisting' => $result->getUpsertedCount() == 0,
        ];
    }

    /**
     * Remove records from this collection
     *
     * @link http://www.php.net/manual/en/mongocollection.remove.php
     * @param array $criteria Query criteria for the documents to delete.
     * @param array $options An array of options for the remove operation.
     * @throws MongoCursorException
     * @throws MongoCursorTimeoutException
     * @return bool|array Returns an array containing the status of the removal
     * if the "w" option is set. Otherwise, returns TRUE.
     */
    public function remove(array $criteria = [], array $options = [])
    {
        $multiple = isset($options['justOne']) ? !$options['justOne'] : false;
        $method = $multiple ? 'deleteMany' : 'deleteOne';

        return $this->collection->$method($criteria, $options);
    }

    /**
     * Querys this collection
     *
     * @link http://www.php.net/manual/en/mongocollection.find.php
     * @param array $query The fields for which to search.
     * @param array $fields Fields of the results to return.
     * @return MongoCursor
     */
    public function find(array $query = [], array $fields = [])
    {
        $cursor = new MongoCursor($this->db->getConnection(), (string)$this, $query, $fields);
        $cursor->setReadPreference($this->getReadPreference());

        return $cursor;
    }

    /**
     * Retrieve a list of distinct values for the given key across a collection
     *
     * @link http://www.php.net/manual/ru/mongocollection.distinct.php
     * @param string $key The key to use.
     * @param array $query An optional query parameters
     * @return array|bool Returns an array of distinct values, or FALSE on failure
     */
    public function distinct($key, array $query = [])
    {
        return array_map([TypeConverter::class, 'convertToLegacyType'], $this->collection->distinct($key, $query));
    }

    /**
     * Update a document and return it
     * @link http://www.php.net/manual/ru/mongocollection.findandmodify.php
     * @param array $query The query criteria to search for.
     * @param array $update The update criteria.
     * @param array $fields Optionally only return these fields.
     * @param array $options An array of options to apply, such as remove the match document from the DB and return it.
     * @return array Returns the original document, or the modified document when new is set.
     */
    public function findAndModify(array $query, array $update = null, array $fields = null, array $options = [])
    {
        $query = TypeConverter::convertLegacyArrayToObject($query);

        if (isset($options['remove'])) {
            unset($options['remove']);
            $document = $this->collection->findOneAndDelete($query, $options);
        } else {
            $update = is_array($update) ? TypeConverter::convertLegacyArrayToObject($update) : [];

            if (isset($options['new'])) {
                $options['returnDocument'] = \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER;
                unset($options['new']);
            }

            $options['projection'] = is_array($fields) ? TypeConverter::convertLegacyArrayToObject($fields) : [];

            $document = $this->collection->findOneAndUpdate($query, $update, $options);
        }

        if ($document) {
            $document = TypeConverter::convertObjectToLegacyArray($document);
        }

        return $document;
    }

    /**
     * Querys this collection, returning a single element
     * @link http://www.php.net/manual/en/mongocollection.findone.php
     * @param array $query The fields for which to search.
     * @param array $fields Fields of the results to return.
     * @return array|null
     */
    public function findOne(array $query = [], array $fields = [])
    {
        $document = $this->collection->findOne(TypeConverter::convertLegacyArrayToObject($query), ['projection' => $fields]);
        if ($document !== null) {
            $document = TypeConverter::convertObjectToLegacyArray($document);
        }

        return $document;
    }

    /**
     * Creates an index on the given field(s), or does nothing if the index already exists
     * @link http://www.php.net/manual/en/mongocollection.createindex.php
     * @param array $keys Field or fields to use as index.
     * @param array $options [optional] This parameter is an associative array of the form array("optionname" => <boolean>, ...).
     * @return array Returns the database response.
     *
     * @todo This method does not yet return the correct result
     */
    public function createIndex(array $keys, array $options = [])
    {
        // Note: this is what the result array should look like
//        $expected = [
//            'createdCollectionAutomatically' => true,
//            'numIndexesBefore' => 1,
//            'numIndexesAfter' => 2,
//            'ok' => 1.0
//        ];

        return $this->collection->createIndex($keys, $options);
    }

    /**
     * @deprecated Use MongoCollection::createIndex() instead.
     * Creates an index on the given field(s), or does nothing if the index already exists
     * @link http://www.php.net/manual/en/mongocollection.ensureindex.php
     * @param array $keys Field or fields to use as index.
     * @param array $options [optional] This parameter is an associative array of the form array("optionname" => <boolean>, ...).
     * @return boolean always true
     */
    public function ensureIndex(array $keys, array $options = [])
    {
        $this->createIndex($keys, $options);

        return true;
    }

    /**
     * Deletes an index from this collection
     * @link http://www.php.net/manual/en/mongocollection.deleteindex.php
     * @param string|array $keys Field or fields from which to delete the index.
     * @return array Returns the database response.
     */
    public function deleteIndex($keys)
    {
        if (is_string($keys)) {
            $indexName = $keys;
        } elseif (is_array($keys)) {
            $indexName = self::toIndexString($keys);
        } else {
            throw new \InvalidArgumentException();
        }

        return TypeConverter::convertObjectToLegacyArray($this->collection->dropIndex($indexName));
    }

    /**
     * Delete all indexes for this collection
     * @link http://www.php.net/manual/en/mongocollection.deleteindexes.php
     * @return array Returns the database response.
     */
    public function deleteIndexes()
    {
        return TypeConverter::convertObjectToLegacyArray($this->collection->dropIndexes());
    }

    /**
     * Returns an array of index names for this collection
     * @link http://www.php.net/manual/en/mongocollection.getindexinfo.php
     * @return array Returns a list of index names.
     */
    public function getIndexInfo()
    {
        $convertIndex = function(\MongoDB\Model\IndexInfo $indexInfo) {
            return [
                'v' => $indexInfo->getVersion(),
                'key' => $indexInfo->getKey(),
                'name' => $indexInfo->getName(),
                'ns' => $indexInfo->getNamespace(),
            ];
        };

        return array_map($convertIndex, iterator_to_array($this->collection->listIndexes()));
    }

    /**
     * Counts the number of documents in this collection
     * @link http://www.php.net/manual/en/mongocollection.count.php
     * @param array|stdClass $query
     * @return int Returns the number of documents matching the query.
     */
    public function count($query = [])
    {
        return $this->collection->count($query);
    }

    /**
     * Saves an object to this collection
     *
     * @link http://www.php.net/manual/en/mongocollection.save.php
     * @param array|object $a Array to save. If an object is used, it may not have protected or private properties.
     * @param array $options Options for the save.
     * @throws MongoException if the inserted document is empty or if it contains zero-length keys. Attempting to insert an object with protected and private properties will cause a zero-length key error.
     * @throws MongoCursorException if the "w" option is set and the write fails.
     * @throws MongoCursorTimeoutException if the "w" option is set to a value greater than one and the operation takes longer than MongoCursor::$timeout milliseconds to complete. This does not kill the operation on the server, it is a client-side timeout. The operation in MongoCollection::$wtimeout is milliseconds.
     * @return array|boolean If w was set, returns an array containing the status of the save.
     * Otherwise, returns a boolean representing if the array was not empty (an empty array will not be inserted).
     */
    public function save($a, array $options = [])
    {
        if (is_object($a)) {
            $a = (array)$a;
        }
        if ( ! array_key_exists('_id', $a)) {
            $id = new \MongoId();
        } else {
            $id = $a['_id'];
            unset($a['_id']);
        }
        $filter = ['_id' => $id];
        $filter = TypeConverter::convertLegacyArrayToObject($filter);
        $a = TypeConverter::convertLegacyArrayToObject($a);
        return $this->collection->updateOne($filter, ['$set' => $a], ['upsert' => true]);
    }

    /**
     * Creates a database reference
     *
     * @link http://www.php.net/manual/en/mongocollection.createdbref.php
     * @param array $a Object to which to create a reference.
     * @return array Returns a database reference array.
     */
    public function createDBRef(array $a)
    {
        return \MongoDBRef::create($this->name, $a['_id']);
    }

    /**
     * Fetches the document pointed to by a database reference
     *
     * @link http://www.php.net/manual/en/mongocollection.getdbref.php
     * @param array $ref A database reference.
     * @return array Returns the database document pointed to by the reference.
     */
    public function getDBRef(array $ref)
    {
        return \MongoDBRef::get($this->db, $ref);
    }

    /**
     * @param mixed $keys
     * @static
     * @return string
     */
    protected static function toIndexString($keys)
    {
        $result = '';
        foreach ($keys as $name => $direction) {
            $result .= sprintf('%s_%d', $name, $direction);
        }
        return $result;
    }

    /**
     * Performs an operation similar to SQL's GROUP BY command
     *
     * @link http://www.php.net/manual/en/mongocollection.group.php
     * @param mixed $keys Fields to group by. If an array or non-code object is passed, it will be the key used to group results.
     * @param array $initial Initial value of the aggregation counter object.
     * @param MongoCode $reduce A function that aggregates (reduces) the objects iterated.
     * @param array $condition An condition that must be true for a row to be considered.
     * @return array
     */
    public function group($keys, array $initial, $reduce, array $condition = [])
    {
        if (is_string($reduce)) {
            $reduce = new MongoCode($reduce);
        }
        if ( ! $reduce instanceof MongoCode) {
            throw new \InvalidArgumentExcption('reduce parameter should be a string or MongoCode instance.');
        }
        $command = [
            'group' => [
                'ns' => $this->name,
                '$reduce' => (string)$reduce,
                'initial' => $initial,
                'cond' => $condition,
            ],
        ];

        if ($keys instanceof MongoCode) {
            $command['group']['$keyf'] = (string)$keys;
        } else {
            $command['group']['key'] = $keys;
        }
        if (array_key_exists('condition', $condition)) {
            $command['group']['cond'] = $condition['condition'];
        }
        if (array_key_exists('finalize', $condition)) {
            if ($condition['finalize'] instanceof MongoCode) {
                $condition['finalize'] = (string)$condition['finalize'];
            }
            $command['group']['finalize'] = $condition['finalize'];
        }

        return $this->db->command($command);
    }

    /**
     * Returns an array of cursors to iterator over a full collection in parallel
     *
     * @link http://www.php.net/manual/en/mongocollection.parallelcollectionscan.php
     * @param int $num_cursors The number of cursors to request from the server. Please note, that the server can return less cursors than you requested.
     * @return MongoCommandCursor[]
     */
    public function parallelCollectionScan($num_cursors)
    {
        $this->notImplemented();
    }

    protected function notImplemented()
    {
        throw new \Exception('Not implemented');
    }

    /**
     * @return \MongoDB\Collection
     */
    private function createCollectionObject()
    {
        $options = [
            'readPreference' => $this->readPreference,
            'writeConcern' => $this->writeConcern,
        ];

        if ($this->collection === null) {
            $this->collection = $this->db->getDb()->selectCollection($this->name, $options);
        } else {
            $this->collection = $this->collection->withOptions($options);
        }
    }

    /**
     * @param array $options
     * @return array
     */
    private function convertWriteConcernOptions(array $options)
    {
        if (isset($options['safe'])) {
            $options['w'] = ($options['safe']) ? 1 : 0;
        }

        if (isset($options['wtimeout']) && !isset($options['wTimeoutMS'])) {
            $options['wTimeoutMS'] = $options['wtimeout'];
        }

        if (isset($options['w']) || !isset($options['wTimeoutMS'])) {
            $collectionWriteConcern = $this->getWriteConcern();
            $writeConcern = $this->createWriteConcernFromParameters(
                isset($options['w']) ? $options['w'] : $collectionWriteConcern['w'],
                isset($options['wTimeoutMS']) ? $options['wTimeoutMS'] : $collectionWriteConcern['wtimeout']
            );

            $options['writeConcern'] = $writeConcern;
        }

        unset($options['safe']);
        unset($options['w']);
        unset($options['wTimeout']);
        unset($options['wTimeoutMS']);

        return $options;
    }
}

