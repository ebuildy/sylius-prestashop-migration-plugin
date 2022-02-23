<?php

namespace Jgrasp\PrestashopMigrationPlugin\DataTransformer\Resource\Product;

use Jgrasp\PrestashopMigrationPlugin\DataTransformer\Resource\ResourceTransformerInterface;
use Jgrasp\PrestashopMigrationPlugin\Model\LocaleFetcher;
use Jgrasp\PrestashopMigrationPlugin\Model\ModelInterface;
use Jgrasp\PrestashopMigrationPlugin\Model\Product\ProductModel;
use Jgrasp\PrestashopMigrationPlugin\Repository\EntityRepositoryInterface;
use Jgrasp\PrestashopMigrationPlugin\Repository\Product\ProductRepository;
use Sylius\Component\Core\Formatter\StringInflector;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Product\Generator\SlugGenerator;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Model\ResourceInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

class ProductResourceTransformer implements ResourceTransformerInterface
{
    private ResourceTransformerInterface $transformer;

    /**
     * @var ProductRepository $productRepository
     */
    private EntityRepositoryInterface $productRepository;

    private RepositoryInterface $taxonRepository;

    private RepositoryInterface $channelRepository;

    private FactoryInterface $productTaxonFactory;

    private FactoryInterface $channelPricingFactory;

    private ProductVariantResolverInterface $defaultVariantResolver;

    private SlugGenerator $slugGenerator;

    private LocaleFetcher $localeFetcher;

    public function __construct(
        ResourceTransformerInterface    $transformer,
        EntityRepositoryInterface       $productRepository,
        RepositoryInterface             $taxonRepository,
        RepositoryInterface             $channelRepository,
        FactoryInterface                $productTaxonFactory,
        FactoryInterface                $channelPricingFactory,
        ProductVariantResolverInterface $productVariantResolver,
        SlugGenerator                   $slugGenerator,
        LocaleFetcher                   $localeFetcher,
    )
    {
        $this->transformer = $transformer;
        $this->productRepository = $productRepository;
        $this->taxonRepository = $taxonRepository;
        $this->channelRepository = $channelRepository;
        $this->productTaxonFactory = $productTaxonFactory;
        $this->channelPricingFactory = $channelPricingFactory;
        $this->defaultVariantResolver = $productVariantResolver;
        $this->slugGenerator = $slugGenerator;
        $this->localeFetcher = $localeFetcher;
    }

    /**
     * @param ProductModel $model
     *
     * @return ResourceInterface
     * @throws \Exception
     */
    public function transform(ModelInterface $model): ResourceInterface
    {
        /**
         * @var ProductInterface $product
         */
        $product = $this->transformer->transform($model);

        foreach ($this->localeFetcher->getLocales() as $locale) {

            $product->setCurrentLocale($locale->getCode());
            $product->setFallbackLocale($locale->getCode());

            $product->setName($model->getName($locale->getCode()));
            $product->setDescription($model->getDescription($locale->getCode()));

            if (null === $model->code && null === $product->getCode()) {
                $product->setCode($product->getName());
            }

            $this->addSlug($product, $model, $locale);
        }

        $this->addCode($product, $model);
        $this->addTaxons($product, $model);
        $this->addChannel($product, $model);
        $this->addVariant($product);

        return $product;
    }

    private function addSlug(ProductInterface $product, ProductModel $model, LocaleInterface $locale): void
    {
        $product->setSlug($model->getSlug($locale->getCode()));

        $slugs = $this->productRepository->findBySlug($product->getSlug());

        if (count($slugs) > 1) {
            $product->setSlug($product->getSlug().'-'.$model->id);
        }

        $product->setSlug($this->slugGenerator->generate($product->getSlug()));
    }

    private function addCode(ProductInterface $product, ProductModel $model): void
    {
        //If code (or prestashop reference) is not unique, make sure it will be.
        $list = $this->productRepository->findByReference($product->getCode());

        if (count($list) > 1) {
            $product->setCode($product->getCode().'-'.$model->id);
        }

        $product->setCode(StringInflector::nameToCode($product->getCode()));
    }

    private function addTaxons(ProductInterface $product, ProductModel $model): void
    {
        $categories = $this->productRepository->getCategories($model->id);

        foreach ($categories as $category) {
            $categoryId = (int)$category['id_category'];

            /**
             * @var TaxonInterface|null $taxon
             */
            $taxon = $this->taxonRepository->findOneBy(['prestashopId' => $categoryId]);

            if (null === $taxon) {
                continue;
            }

            /**
             * @var ProductTaxonInterface $productTaxon
             */
            $productTaxon = $this->productTaxonFactory->createNew();
            $productTaxon->setProduct($product);
            $productTaxon->setTaxon($taxon);
            $productTaxon->setPosition((int)$category['position']);

            if ($product->hasProductTaxon($productTaxon)) {
                $product->addProductTaxon($productTaxon);
            }

            if ($model->categoryDefaultId === $categoryId) {
                $product->setMainTaxon($taxon);
            }
        }
    }

    private function addVariant(ProductInterface $product): void
    {
        $productVariant = $this->defaultVariantResolver->getVariant($product);

        $productVariant->setCode($product->getCode());
        $productVariant->setName($product->getName());
    }

    private function addChannel(ProductInterface $product, ProductModel $model): void
    {
        $shops = $this->productRepository->getShops($model->id);

        foreach ($shops as $shop) {
            $shopId = (int)$shop['id_shop'];

            /**
             * @var ChannelInterface|null $channel
             */
            $channel = $this->channelRepository->findOneBy(['prestashopId' => $shopId]);

            if (null === $channel) {
                continue;
            }

            $product->addChannel($channel);

            /**
             * @var ProductVariantInterface $productVariant
             */
            $productVariant = $this->defaultVariantResolver->getVariant($product);
            $channelPricing = $productVariant->getChannelPricingForChannel($channel);

            if (null === $channelPricing) {
                /** @var ChannelPricingInterface $channelPricing */
                $channelPricing = $this->channelPricingFactory->createNew();
                $channelPricing->setChannelCode($channel->getCode());
            }

            $channelPricing->setPrice($shop['price'] * 100);
            $productVariant->addChannelPricing($channelPricing);
        }
    }
}
