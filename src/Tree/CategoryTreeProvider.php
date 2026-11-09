<?php

declare(strict_types=1);

namespace Answeb\CategoryTree\Tree;

use Answeb\CategoryTree\Move\CategoryPositionRepository;
use Db;

class CategoryTreeProvider
{
    public function __construct(
        private readonly CategoryPositionRepository $positionRepository,
    ) {
    }

    /**
     * Arbre à plat depuis l'Accueil : parents avant enfants, frères dans l'ordre d'affichage.
     * Parcours par id_parent (et non nleft/nright) pour ne pas dépendre de la fraîcheur du nested set.
     *
     * @return array<int, array{id: int, parentId: int, position: int, name: string, active: bool, locked: bool}>
     */
    public function getTree(int $shopId, int $langId): array
    {
        $homeId = $this->positionRepository->getHomeCategoryId($shopId);

        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, c.`active`, cs.`position`, cl.`name`
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $shopId . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON (cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $langId . ' AND cl.`id_shop` = ' . $shopId . ')
            ORDER BY cs.`position` ASC, c.`id_category` ASC',
            true,
            false
        ) ?: [];

        $nodes = [];
        $childrenByParent = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_category'];
            $nodes[$id] = [
                'id' => $id,
                'parentId' => (int) $row['id_parent'],
                'position' => (int) $row['position'],
                'name' => (string) $row['name'],
                'active' => (bool) $row['active'],
                'locked' => $id === $homeId,
            ];
            $childrenByParent[(int) $row['id_parent']][] = $id;
        }

        if (!isset($nodes[$homeId])) {
            return [];
        }

        $tree = [];
        $queue = [$homeId];
        while ($queue) {
            $id = array_shift($queue);
            $tree[] = $nodes[$id];
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return $tree;
    }
}
