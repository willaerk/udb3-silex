<?php

namespace CultuurNet\UDB3\Silex\Migrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Creates the search index table for variations.
 */
class Version20150615114627 extends AbstractMigration
{
    /**
     * @param Schema $schema
     */
    public function up(Schema $schema)
    {
        // @see \CultuurNet\UDB3\Variations\ReadModel\Search\Doctrine\DBALRepository
        $table = $schema->createTable('event_variation_search_index');

        $table->addColumn(
            'id',
            'string',
            array('length' => 36, 'notnull' => false)
        );
        $table->addColumn(
            'event',
            'text',
            array('notnull' => false)
        );
        $table->addColumn(
            'owner',
            'string',
            array('length' => 36, 'notnull' => false)
        );
        $table->addColumn(
            'purpose',
            'text',
            array('notnull' => false)
        );

        $table->setPrimaryKey(array('id'));
    }

    /**
     * @param Schema $schema
     */
    public function down(Schema $schema)
    {
        $schema->dropTable('event_variation_search_index');
    }
}
