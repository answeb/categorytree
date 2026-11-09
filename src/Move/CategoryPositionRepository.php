<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Move;

use Db;

/**
 * Accès SQL aux catégories via la connexion legacy, celle utilisée par Category::update().
 */
class CategoryPositionRepository
{
    public function getHomeCategoryId(int $shopId): int
    {
        return (int) $this->db()->getValue(
            'SELECT `id_category` FROM `' . _DB_PREFIX_ . 'shop` WHERE `id_shop` = ' . $shopId,
            false
        );
    }

    /**
     * @return array{id: int, parentId: int, nleft: int, nright: int}|null
     */
    public function findCategory(int $categoryId): ?array
    {
        $row = $this->db()->getRow(
            'SELECT `id_category`, `id_parent`, `nleft`, `nright`
            FROM `' . _DB_PREFIX_ . 'category`
            WHERE `id_category` = ' . $categoryId,
            false
        );

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id_category'],
            'parentId' => (int) $row['id_parent'],
            'nleft' => (int) $row['nleft'],
            'nright' => (int) $row['nright'],
        ];
    }

    /**
     * @return int[] Enfants directs, dans l'ordre d'affichage
     */
    public function getChildrenIds(int $parentId, int $shopId): array
    {
        $rows = $this->db()->executeS(
            'SELECT c.`id_category`
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $shopId . ')
            WHERE c.`id_parent` = ' . $parentId . '
            ORDER BY cs.`position` ASC, c.`id_category` ASC',
            true,
            false
        );

        return array_map('intval', array_column($rows ?: [], 'id_category'));
    }

    /**
     * Écrit les positions 0..n-1 dans category_shop et dans le champ legacy category.position.
     *
     * @param int[] $orderedIds
     */
    public function writePositions(array $orderedIds, int $shopId): void
    {
        $db = $this->db();
        foreach (array_values($orderedIds) as $position => $categoryId) {
            $categoryId = (int) $categoryId;
            $ok = $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'category_shop`
                SET `position` = ' . $position . '
                WHERE `id_category` = ' . $categoryId . ' AND `id_shop` = ' . $shopId,
                false
            ) && $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'category`
                SET `position` = ' . $position . '
                WHERE `id_category` = ' . $categoryId,
                false
            );

            if (!$ok) {
                throw new \RuntimeException(sprintf('Unable to write position of category %d.', $categoryId));
            }
        }
    }

    public function beginTransaction(): void
    {
        $this->db()->getLink()->beginTransaction();
    }

    public function commit(): void
    {
        $this->db()->getLink()->commit();
    }

    public function rollBack(): void
    {
        $link = $this->db()->getLink();
        if ($link->inTransaction()) {
            $link->rollBack();
        }
    }

    private function db(): Db
    {
        return Db::getInstance();
    }
}
