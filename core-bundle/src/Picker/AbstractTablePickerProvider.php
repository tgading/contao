<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Picker;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DcaLoader;
use Doctrine\DBAL\Connection;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AbstractTablePickerProvider implements PickerProviderInterface, DcaPickerProviderInterface, PickerMenuInterface
{
    private const PREFIX = 'dc.';
    private const PREFIX_LENGTH = 3;

    /**
     * @var ContaoFramework
     */
    private $framework;

    /**
     * @var FactoryInterface
     */
    private $menuFactory;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var Connection
     */
    private $connection;

    public function __construct(ContaoFramework $framework, FactoryInterface $menuFactory, RouterInterface $router, TranslatorInterface $translator, Connection $connection)
    {
        $this->framework = $framework;
        $this->menuFactory = $menuFactory;
        $this->router = $router;
        $this->translator = $translator;
        $this->connection = $connection;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl(PickerConfig $config): string
    {
        $table = $this->getTableFromContext($config->getContext());
        $modules = $this->getModulesForTable($table);

        if (0 === \count($modules)) {
            throw new \RuntimeException(sprintf('Table "%s" is not in any back end module (context: %s)', $table, $config->getContext()));
        }

        $module = array_keys($modules)[0];
        [$ptable, $pid] = $this->getPtableAndPid($table, $config->getValue());

        if ($ptable) {
            foreach ($modules as $key => $tables) {
                if (\in_array($ptable, $tables, true)) {
                    $module = $key;
                    break;
                }
            }
        }

        // If the table is the first in the module, we do not need to add table=xy to the URL
        if (0 === array_search($table, $modules[$module], true)) {
            return $this->getUrlForValue($config, $module);
        }

        return $this->getUrlForValue($config, $module, $table, $pid);
    }

    /**
     * {@inheritdoc}
     */
    public function addMenuItems(ItemInterface $menu, PickerConfig $config): void
    {
        $modules = array_keys($this->getModulesForTable($this->getTableFromContext($config->getContext())));

        foreach ($modules as $name) {
            $params = [
                'do' => $name,
                'popup' => '1',
                'picker' => $config->cloneForCurrent($this->getName().'.'.$name)->urlEncode(),
            ];

            $menu->addChild($this->menuFactory->createItem(
                $name,
                [
                    'label' => $this->translator->trans('MOD.'.$name.'.0', [], 'contao_default'),
                    'linkAttributes' => ['class' => $name],
                    'current' => $this->isCurrent($config) && $name === substr($config->getCurrent(), \strlen($this->getName().'.')),
                    'uri' => $this->router->generate('contao_backend', $params),
                ]
            ));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createMenuItem(PickerConfig $config): ItemInterface
    {
        $menu = $this->menuFactory->createItem('picker');

        $this->addMenuItems($menu, $config);

        return $menu->getFirstChild();
    }

    /**
     * {@inheritdoc}
     */
    public function supportsContext($context): bool
    {
        if (0 !== strpos($context, self::PREFIX)) {
            return false;
        }

        $table = $this->getTableFromContext($context);

        $this->framework->initialize();
        $this->framework->createInstance(DcaLoader::class, [$table])->load();

        return $this->getDataContainer() === $GLOBALS['TL_DCA'][$table]['config']['dataContainer']
            && 0 !== \count($this->getModulesForTable($table))
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsValue(PickerConfig $config): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isCurrent(PickerConfig $config): bool
    {
        return 0 === strpos($config->getCurrent(), $this->getName().'.');
    }

    /**
     * {@inheritdoc}
     */
    public function getDcaTable(PickerConfig $config = null): string
    {
        if (null === $config) {
            return '';
        }

        return $this->getTableFromContext($config->getContext());
    }

    /**
     * {@inheritdoc}
     */
    public function getDcaAttributes(PickerConfig $config): array
    {
        $attributes = ['fieldType' => 'radio'];

        if ($fieldType = $config->getExtra('fieldType')) {
            $attributes['fieldType'] = $fieldType;
        }

        if ($source = $config->getExtra('source')) {
            $attributes['preserveRecord'] = $source;
        }

        if ($value = $config->getValue()) {
            $attributes['value'] = array_map('\intval', explode(',', $value));
        }

        return $attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function convertDcaValue(PickerConfig $config, $value)
    {
        return (int) $value;
    }

    protected function getModulesForTable(string $table): array
    {
        $modules = [];

        foreach ($GLOBALS['BE_MOD'] as $v) {
            foreach ($v as $name => $module) {
                if (
                    isset($module['tables'])
                    && \is_array($module['tables'])
                    && \in_array($table, $module['tables'], true)
                ) {
                    $modules[$name] = array_values($module['tables']);
                }
            }
        }

        return $modules;
    }

    protected function getTableFromContext(string $context): string
    {
        return substr($context, self::PREFIX_LENGTH);
    }

    protected function getUrlForValue(PickerConfig $config, string $module, string $table = null, int $pid = null): string
    {
        $params = [
            'do' => $module,
            'popup' => '1',
            'picker' => $config->cloneForCurrent($this->getName().'.'.$module)->urlEncode(),
        ];

        if (null !== $table) {
            $params['table'] = $table;

            if (null !== $pid) {
                $params['id'] = $pid;
            }
        }

        return $this->router->generate('contao_backend', $params);
    }

    protected function getPtableAndPid(string $table, string $value): array
    {
        // Use the first value if array to find a database record
        $id = (int) explode(',', $value)[0];

        if (!$value) {
            return [null, null];
        }

        $this->framework->initialize();
        $this->framework->createInstance(DcaLoader::class, [$table])->load();

        $pid = null;
        $ptable = $GLOBALS['TL_DCA'][$table]['config']['ptable'] ?? null;
        $dynamicPtable = $GLOBALS['TL_DCA'][$table]['config']['dynamicPtable'] ?? false;

        if (!$ptable && !$dynamicPtable) {
            return [null, null];
        }

        $qb = $this->connection->createQueryBuilder();
        $qb->select('pid')->from($table)->where($qb->expr()->eq('id', $id));

        if ($dynamicPtable) {
            $qb->addSelect('ptable');
        }

        $data = $qb->execute()->fetch();

        if (false === $data) {
            return [null, null];
        }

        $pid = (int) $data['pid'];

        if ($dynamicPtable) {
            $ptable = $data['ptable'] ?: $ptable;

            if (!$ptable) {
                $ptable = 'tl_article'; // backwards compatibility
            }
        }

        return [$ptable, $pid];
    }

    /**
     * Returns the DataContainer name supported by this picker (e.g. "Table" for DC_Table).
     */
    abstract protected function getDataContainer(): string;
}
