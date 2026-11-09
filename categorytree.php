<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class CategoryTree extends Module
{
    public function __construct()
    {
        $this->name = 'categorytree';
        $this->tab = 'administration';
        $this->author = 'Answeb';
        $this->version = '1.0.0';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.1.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Arborescence des catégories', [], 'Modules.Categorytree.Admin');
        $this->description = $this->trans(
            'Réorganisez l\'arbre des catégories par glisser-déposer.',
            [],
            'Modules.Categorytree.Admin'
        );

        $this->tabs = [
            [
                'route_name' => 'category_tree_index',
                'class_name' => 'Admincategorytree',
                'visible' => true,
                'name' => $this->getTabNames(),
                'parent_class_name' => 'AdminCatalog',
                'wording' => 'Arborescence des catégories',
                'wording_domain' => 'Modules.Categorytree.Admin',
            ],
        ];
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    private function getTabNames(): array
    {
        $names = [];
        foreach (Language::getLanguages(false) as $language) {
            $names[$language['locale']] = $this->trans(
                'Arborescence des catégories',
                [],
                'Modules.Categorytree.Admin',
                $language['locale']
            );
        }

        return $names;
    }
}
