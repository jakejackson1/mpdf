<?php

namespace Mpdf;

use Mpdf\Color\ColorConverter;
use Mpdf\Color\ColorModeConverter;
use Mpdf\Color\ColorSpaceRestrictor;
use Mpdf\Css\BorderMerger;
use Mpdf\Css\CssMerger;
use Mpdf\Css\CssParser;
use Mpdf\Css\InlinePropertyConverter;
use Mpdf\Css\InlineStyleParser;
use Mpdf\Css\NormalizeProperties;
use Mpdf\Css\SelectorParser;
use Mpdf\Css\ShadowParser;
use Mpdf\File\LocalContentLoader;
use Mpdf\Fonts\FontCache;
use Mpdf\Fonts\FontFileFinder;
use Mpdf\Http\CurlHttpClient;
use Mpdf\Http\SocketHttpClient;
use Mpdf\Image\ImageProcessor;
use Mpdf\Pdf\Protection;
use Mpdf\Pdf\Protection\UniqidGenerator;
use Mpdf\Writer\BaseWriter;
use Mpdf\Writer\BackgroundWriter;
use Mpdf\Writer\ColorWriter;
use Mpdf\Writer\BookmarkWriter;
use Mpdf\Writer\FontWriter;
use Mpdf\Writer\FormWriter;
use Mpdf\Writer\ImageWriter;
use Mpdf\Writer\JavaScriptWriter;
use Mpdf\Writer\MetadataWriter;
use Mpdf\Writer\OptionalContentWriter;
use Mpdf\Writer\PageWriter;
use Mpdf\Writer\ResourceWriter;
use Mpdf\Ua\UaState;
use Mpdf\Ua\StructureTree;
use Mpdf\Ua\MarkedContentHelper;
use Mpdf\Ua\StructureWriter;
use Mpdf\Ua\AriaIdResolver;
use Mpdf\Ua\LigatureActualTextWriter;
use Mpdf\Ua\AnchorState;
use Mpdf\Ua\InlineStructStack;
use Mpdf\Ua\ImageMap\ImageMapRegistry;
use Mpdf\Ua\Import\FpdiStructMerger;
use Psr\Log\LoggerInterface;

class ServiceFactory
{

	/**
	 * @var \Mpdf\Container\ContainerInterface|null
	 */
	private $container;

	public function __construct($container = null)
	{
		$this->container = $container;
	}

	public function getServices(
		Mpdf $mpdf,
		LoggerInterface $logger,
		$config,
		$languageToFont,
		$scriptToLanguage,
		$fontDescriptor,
		$bmp,
		$directWrite,
		$wmf
	) {
		$sizeConverter = new SizeConverter($mpdf->dpi, $mpdf->default_font_size, $mpdf, $logger);

		$colorModeConverter = new ColorModeConverter();
		$colorSpaceRestrictor = new ColorSpaceRestrictor(
			$mpdf,
			$colorModeConverter
		);
		$colorConverter = new ColorConverter($mpdf, $colorModeConverter, $colorSpaceRestrictor);

		$tableOfContents = new TableOfContents($mpdf, $sizeConverter);

		$cacheBasePath = $config['tempDir'] . '/mpdf';

		$cache = new Cache($cacheBasePath, $config['cacheCleanupInterval']);
		$fontCache = new FontCache(new Cache($cacheBasePath . '/ttfontdata', $config['cacheCleanupInterval']));

		$fontFileFinder = new FontFileFinder($config['fontDir']);

		if ($this->container && $this->container->has('httpClient')) {
			$httpClient = $this->container->get('httpClient');
		} elseif (\function_exists('curl_init')) {
			$httpClient = new CurlHttpClient($mpdf, $logger);
		} else {
			$httpClient = new SocketHttpClient($logger);
		}

		$localContentLoader = $this->container && $this->container->has('localContentLoader')
			? $this->container->get('localContentLoader')
			: new LocalContentLoader();

		$assetFetcher = $this->container && $this->container->has('assetFetcher')
			? $this->container->get('assetFetcher')
			: new AssetFetcher($mpdf, $localContentLoader, $httpClient, $logger);

		$normalizeProperties = new NormalizeProperties($mpdf, $sizeConverter, $colorConverter);
		$selectorParser = new SelectorParser($mpdf);
		$inlineStyleParser = new InlineStyleParser($normalizeProperties);
		$inlinePropertyConverter = new InlinePropertyConverter($colorConverter);
		$borderMerger = new BorderMerger();

		$cssParser = new CssParser($mpdf, $cache, $sizeConverter, $colorConverter, $assetFetcher);

		$cssMerger = new CssMerger(
			$mpdf,
			$normalizeProperties,
			$inlineStyleParser,
			$selectorParser,
			$inlinePropertyConverter,
			$colorConverter,
			$borderMerger
		);

		$cssManager = new CssManager($cssParser, $cssMerger);

		$otl = new Otl($mpdf, $fontCache);

		$protection = new Protection(new UniqidGenerator());

		$writer = new BaseWriter($mpdf, $protection);

		$gradient = new Gradient($mpdf, $sizeConverter, $colorConverter, $writer);

		$formWriter = new FormWriter($mpdf, $writer);

		$form = new Form($mpdf, $otl, $colorConverter, $writer, $formWriter);

		$hyphenator = new Hyphenator($mpdf);

		$imageProcessor = new ImageProcessor(
			$mpdf,
			$otl,
			$cssManager,
			$sizeConverter,
			$colorConverter,
			$colorModeConverter,
			$cache,
			$languageToFont,
			$scriptToLanguage,
			$assetFetcher,
			$logger
		);

		// Build the UA collaborators first; none of them take UaState — each
		// receives only the specific pieces it needs (StructureTree,
		// MarkedContentHelper, $writer, $mpdf) so there is no construction-time
		// cycle when UaState is built below.
		$structureTree            = new StructureTree();
		$markedContentHelper      = new MarkedContentHelper($writer);
		$structureWriter          = new StructureWriter($mpdf, $writer, $structureTree);
		$ariaIdResolver           = new AriaIdResolver($structureTree);
		$ligatureActualTextWriter = new LigatureActualTextWriter($writer, $markedContentHelper);
		$fpdiStructMerger         = new FpdiStructMerger($mpdf, $structureTree);
		$inlineStructStack        = new InlineStructStack();
		$anchorState              = new AnchorState();
		$imageMapRegistry         = new ImageMapRegistry($mpdf, $structureTree, $anchorState);

		// Build the facade last — fully populated in a single constructor call,
		// with no setter-based wiring needed afterwards.
		$uaState = new UaState(
			$structureTree,
			$markedContentHelper,
			$structureWriter,
			$ariaIdResolver,
			$ligatureActualTextWriter,
			$fpdiStructMerger,
			$inlineStructStack,
			$anchorState,
			$imageMapRegistry
		);

		// Inject the facade back into StructureTree so its annotation-level
		// ParentTree-key allocator (nextAnnotStructParent/reserveAnnotStructParent)
		// shares the same counter as page /StructParents — see StructureTree
		// docblock for the collision rationale.
		$structureTree->setUaState($uaState);

		// Wire the lazy UaState resolver on ImageMapRegistry so it can route
		// PDFUAauto warnings through UaState::addWarning(). Done after the
		// facade is fully built to avoid a construction-time cycle.
		$imageMapRegistry->setLazyUaWiring(function () use ($uaState) {
			return $uaState;
		});

		$tag = new Tag(
			$mpdf,
			$cache,
			$cssManager,
			$form,
			$otl,
			$tableOfContents,
			$sizeConverter,
			$colorConverter,
			$imageProcessor,
			$languageToFont,
			$uaState
		);

		$fontWriter = new FontWriter($mpdf, $writer, $fontCache, $fontDescriptor);
		$metadataWriter = new MetadataWriter($mpdf, $writer, $form, $protection, $uaState, $logger);
		$imageWriter = new ImageWriter($mpdf, $writer);
		$pageWriter = new PageWriter($mpdf, $form, $writer, $metadataWriter, $uaState);
		$bookmarkWriter = new BookmarkWriter($mpdf, $writer);
		$optionalContentWriter = new OptionalContentWriter($mpdf, $writer);
		$colorWriter = new ColorWriter($mpdf, $writer);
		$backgroundWriter = new BackgroundWriter($mpdf, $writer);
		$javaScriptWriter = new JavaScriptWriter($mpdf, $writer);

		$resourceWriter = new ResourceWriter(
			$mpdf,
			$writer,
			$colorWriter,
			$fontWriter,
			$imageWriter,
			$formWriter,
			$optionalContentWriter,
			$backgroundWriter,
			$bookmarkWriter,
			$metadataWriter,
			$javaScriptWriter,
			$logger,
			$uaState
		);

		return [
			'uaState' => $uaState,
			'otl' => $otl,
			'bmp' => $bmp,
			'cache' => $cache,
			'cssManager' => $cssManager,
			'directWrite' => $directWrite,
			'fontCache' => $fontCache,
			'fontFileFinder' => $fontFileFinder,
			'form' => $form,
			'gradient' => $gradient,
			'tableOfContents' => $tableOfContents,
			'tag' => $tag,
			'wmf' => $wmf,
			'sizeConverter' => $sizeConverter,
			'colorConverter' => $colorConverter,
			'hyphenator' => $hyphenator,
			'localContentLoader' => $localContentLoader,
			'httpClient' => $httpClient,
			'assetFetcher' => $assetFetcher,
			'imageProcessor' => $imageProcessor,
			'protection' => $protection,

			'languageToFont' => $languageToFont,
			'scriptToLanguage' => $scriptToLanguage,

			'writer' => $writer,
			'fontWriter' => $fontWriter,
			'metadataWriter' => $metadataWriter,
			'imageWriter' => $imageWriter,
			'formWriter' => $formWriter,
			'pageWriter' => $pageWriter,
			'bookmarkWriter' => $bookmarkWriter,
			'optionalContentWriter' => $optionalContentWriter,
			'colorWriter' => $colorWriter,
			'backgroundWriter' => $backgroundWriter,
			'javaScriptWriter' => $javaScriptWriter,
			'resourceWriter' => $resourceWriter
		];
	}

	public function getServiceIds()
	{
		return [
			'uaState',
			'otl',
			'bmp',
			'cache',
			'cssManager',
			'directWrite',
			'fontCache',
			'fontFileFinder',
			'form',
			'gradient',
			'tableOfContents',
			'tag',
			'wmf',
			'sizeConverter',
			'colorConverter',
			'hyphenator',
			'localContentLoader',
			'httpClient',
			'assetFetcher',
			'imageProcessor',
			'protection',
			'languageToFont',
			'scriptToLanguage',
			'writer',
			'fontWriter',
			'metadataWriter',
			'imageWriter',
			'formWriter',
			'pageWriter',
			'bookmarkWriter',
			'optionalContentWriter',
			'colorWriter',
			'backgroundWriter',
			'javaScriptWriter',
			'resourceWriter',
		];
	}

}
