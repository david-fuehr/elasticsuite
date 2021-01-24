<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile ElasticSuite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteSwatches
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2020 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace Smile\ElasticsuiteSwatches\Helper;

use Magento\Catalog\Api\Data\ProductInterface as Product;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory as SwatchCollectionFactory;
use Magento\Swatches\Model\SwatchAttributesProvider;
use Magento\Swatches\Model\SwatchAttributeType;

/**
 * ElasticSuite swatches helper.
 * Allow to load swatches images from a multivalued attribute filter.
 *
 * @category Smile
 * @package  Smile\ElasticsuiteSwatches
 * @author   Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class Swatches extends \Magento\Swatches\Helper\Data
{
    /**
     * @var Collection
     */
    private $attributeCollection;

    /**
     * Attribute codes that should be loaded for variation products
     *
     * @var string[]
     */
    private $variationSelectAttributes;

    /**
     * Attribute codes for all attributes with frontend_input = 'media_gallery'
     *
     * @var string[]
     */
    private $imageAttributes;

    /**
     * @param CollectionFactory $productCollectionFactory
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param SwatchCollectionFactory $swatchCollectionFactory
     * @param UrlBuilder $urlBuilder
     * @param Json|null $serializer
     * @param SwatchAttributesProvider|null $swatchAttributesProvider
     * @param SwatchAttributeType|null $swatchTypeChecker
     * @param Collection|null $attributeCollection
     * @param array $variationSelectAttributes
     */
    public function __construct(
        CollectionFactory $productCollectionFactory,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        SwatchCollectionFactory $swatchCollectionFactory,
        UrlBuilder $urlBuilder,
        Json $serializer = null,
        SwatchAttributesProvider $swatchAttributesProvider = null,
        SwatchAttributeType $swatchTypeChecker = null,
        Collection $attributeCollection = null,
        array $variationSelectAttributes = []
    ) {
        parent::__construct(
            $productCollectionFactory,
            $productRepository,
            $storeManager,
            $swatchCollectionFactory,
            $urlBuilder,
            $serializer,
            $swatchAttributesProvider,
            $swatchTypeChecker);

        $this->attributeCollection = $attributeCollection
            ?: ObjectManager::getInstance()->get(Collection::class);
        $this->variationSelectAttributes = $variationSelectAttributes;
    }


    /**
     * @SuppressWarnings(PHPMD.ElseExpression)
     * {@inheritDoc}
     */
    public function loadVariationByFallback(Product $parentProduct, array $attributes)
    {
        if (!$this->isProductHasSwatch($parentProduct)) {
            return false;
        }

        $variation = false;

        if ($parentProduct->getDocumentSource() !== null) {
            $variation = $this->loadVariationsFromSearchIndex($parentProduct, $attributes);
        } else {
            $productCollection = $this->productCollectionFactory->create();
            $this->addFilterByParent($productCollection, $parentProduct->getId());

            $configurableAttributes = $this->getAttributesFromConfigurable($parentProduct);
            $allAttributesArray     = [];

            foreach ($configurableAttributes as $attribute) {
                $allAttributesArray[$attribute['attribute_code']] = $attribute['default_value'];
                if ($attribute->usesSource() && isset($attributes[$attribute->getAttributeCode()])) {
                    // If value is the attribute label, replace it by the optionId.
                    $optionId = $attribute->getSource()->getOptionId($attributes[$attribute->getAttributeCode()]);
                    if ($optionId) {
                        $attributes[$attribute->getAttributeCode()] = $optionId;
                    }
                }
            }

            $resultAttributesToFilter = array_merge(
                $attributes,
                array_diff_key($allAttributesArray, $attributes)
            );

            $this->addFilterByAttributes($productCollection, $resultAttributesToFilter);
            $this->addVariationSelectAttributes($productCollection);

            $variationProduct = $productCollection->getFirstItem();

            if ($variationProduct && $variationProduct->getId()) {
                $variation = $variationProduct;
            }
        }

        return $variation;
    }

    /**
     * Add desired collection select attributes
     *
     * @param ProductCollection $productCollection
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function addVariationSelectAttributes(ProductCollection $productCollection): void
    {
        $productCollection->addMediaGalleryData();
        foreach ($this->getImageAttributes() as $imageTypeAttribute) {
            $productCollection->addAttributeToSelect($imageTypeAttribute);
        }

        foreach ($this->variationSelectAttributes as $additionalAttribute) {
            $productCollection->addAttributeToSelect($additionalAttribute);
        }
    }

    /**
     * @return array|string[]
     */
    private function getImageAttributes()
    {
        if (!$this->imageAttributes) {
            $this->attributeCollection->addFieldToFilter('frontend_input', 'media_image');
            $this->imageAttributes = $this->attributeCollection->getColumnValues('attribute_code');
        }
        return $this->imageAttributes;
    }

    /**
     * Retrieve options ids from a labels array.
     *
     * @param Attribute $attribute Attribute.
     * @param string[]  $labels    Labels
     *
     * @return integer[]
     */
    public function getOptionIds(Attribute $attribute, $labels)
    {
        $optionIds = [];

        if (!is_array($labels)) {
            $labels = [$labels];
        }

        $options = $attribute->getSource()->getAllOptions();

        foreach ($labels as $label) {
            foreach ($options as $option) {
                if ($option['label'] == $label) {
                    $optionIds[] = (int) $option['value'];
                }
            }
        }

        return $optionIds;
    }

    /**
     * {@inheritDoc}
     */
    protected function addFilterByAttributes(ProductCollection $productCollection, array $attributes)
    {
        foreach ($attributes as $code => $option) {
            if (!is_array($option)) {
                $option = [$option];
            }
            $productCollection->addAttributeToFilter($code, ['in' => $option]);
        }
    }

    /**
     * Load variations for a given product with data coming from the search index.
     *
     * @param Product $parentProduct Parent Product
     * @param array   $attributes    Attributes
     *
     * @return bool|\Magento\Catalog\Api\Data\ProductInterface
     */
    private function loadVariationsFromSearchIndex(Product $parentProduct, array $attributes)
    {
        $documentSource = $parentProduct->getDocumentSource();
        $childrenIds    = isset($documentSource['children_ids']) ? $documentSource['children_ids'] : [];
        $variation      = false;

        if (!empty($childrenIds)) {
            $childrenIds = array_map('intval', $childrenIds);

            $productCollection = $this->productCollectionFactory->create();
            $productCollection->addIdFilter($childrenIds);

            $configurableAttributes = $this->getAttributesFromConfigurable($parentProduct);
            $allAttributesArray     = [];

            foreach ($configurableAttributes as $attribute) {
                foreach ($attribute->getOptions() as $option) {
                    $allAttributesArray[$attribute['attribute_code']][] = (int) $option->getValue();
                }
            }

            $resultAttributesToFilter = array_merge($attributes, array_diff_key($allAttributesArray, $attributes));

            $this->addFilterByAttributes($productCollection, $resultAttributesToFilter);
            $this->addVariationSelectAttributes($productCollection);

            $variationProduct = $productCollection->getFirstItem();

            if ($variationProduct && $variationProduct->getId()) {
                $variation = $variationProduct;
            }
        }

        return $variation;
    }

    /**
     * Filter a collection by its parent.
     * Inherited method since it's private in the parent.
     *
     * @param ProductCollection $productCollection Product Collection
     * @param integer           $parentId          Parent Product Id
     *
     * @return void
     */
    private function addFilterByParent(ProductCollection $productCollection, $parentId)
    {
        $tableProductRelation = $productCollection->getTable('catalog_product_relation');
        $productCollection->getSelect()->join(
            ['pr' => $tableProductRelation],
            'e.entity_id = pr.child_id'
        )->where('pr.parent_id = ?', $parentId);
    }
}
