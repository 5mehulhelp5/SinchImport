<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Handles bullet points as well as the product summary points for display on listing pages
 * @package SITC\Sinchimport\Model\Import
 */
class BulletPoints extends AbstractImportSection {
    const LOG_PREFIX = "BulletPoints: ";
    const LOG_FILENAME = "bullet_points";

    private Data $dataHelper;

    private string $bulletPointsTable;
    private string $sinchProductsTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;

        $this->bulletPointsTable = $this->getTableName('sinch_bullet_points');
        $this->sinchProductsTable = $this->getTableName('sinch_products');
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_BULLET_POINTS];
    }

    public function parse(): void
    {
        $this->createTableIfRequired();
        $conn = $this->getConnection();
        $bulletPointCsv = $this->dlHelper->getSavePath(Download::FILE_BULLET_POINTS);

        $this->startTimingStep('Load Bullet Points');
        /** @noinspection SqlWithoutWhere */
        $conn->query("DELETE FROM {$this->bulletPointsTable}");
        //ID|No|Value
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$bulletPointCsv}'
                INTO TABLE {$this->bulletPointsTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (id, number, value)"
        );
        $this->endTimingStep();
    }

    public function apply(): void
    {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_text = $this->getTableName('catalog_product_entity_text');

        $bulletPointsAttr = $this->dataHelper->getProductAttributeId('sinch_bullet_points');

        //Insert global values for Bullet Points
        $this->startTimingStep('Insert Bullet Point values');
        //Triple pipe delimited to reduce the likelihood of colliding with text in the value
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) 
            SELECT attribute_id, store_id, entity_id, value FROM
            (
                SELECT :bulletPointsAttr as attribute_id, 0 as store_id, cpe.entity_id, GROUP_CONCAT(sbp.value ORDER BY sbp.number SEPARATOR '|||') as value
                FROM {$this->bulletPointsTable} sbp
                INNER JOIN {$catalog_product_entity} cpe
                    ON sbp.id = cpe.sinch_product_id
                GROUP BY sbp.id, cpe.entity_id
            ) new_data
            ON DUPLICATE KEY UPDATE value = new_data.value",
            [":bulletPointsAttr" => $bulletPointsAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert Product Summary values');
        $summaryVals = [];
        for ($i = 1; $i <= 4; $i++) {
            foreach (['title', 'value'] as $attr) {
                $summaryVals["list_summary_{$attr}_{$i}"] = $this->dataHelper->getProductAttributeId("sinch_summary_{$attr}_{$i}");
            }
        }
        foreach ($summaryVals as $field => $attributeId) {
            $this->getConnection()->query(
                "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value)
                SELECT attribute_id, store_id, entity_id, value FROM
                (
                    SELECT :summaryAttr as attribute_id, 0 as store_id, cpe.entity_id, sp.{$field} as value
                    FROM {$this->sinchProductsTable} sp
                    INNER JOIN {$catalog_product_entity} cpe
                        ON sp.sinch_product_id = cpe.sinch_product_id
                ) new_data
                ON DUPLICATE KEY UPDATE
                    value = new_data.value",
                [":summaryAttr" => $attributeId]
            );
        }
        $this->endTimingStep();

        $this->timingPrint();
    }

    private function createTableIfRequired(): void
    {
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bulletPointsTable} (
                id int(10) unsigned NOT NULL COMMENT 'Bullet Point ID',
                number int(10) unsigned NOT NULL COMMENT 'Bullet Point Number',
                value text,
                PRIMARY KEY (id, number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }
}
