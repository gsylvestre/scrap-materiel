<?php

namespace App\Command;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ScrapCommand extends Command
{
    protected static $defaultName = 'app:scrap';

    private $entityManager;
    private $httpClient;

    /**
     * ScrapCommand constructor.
     */
    public function __construct(EntityManagerInterface $entityManager, HttpClientInterface $httpClient)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
    }

    protected function configure()
    {
        $this->setDescription('Scrap materiel.net pour des vidéoproj !');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $productRepository = $this->entityManager->getRepository(Product::class);

        $url = "https://www.materiel.net/videoprojecteur/l559/?sort=32";
        $response = $this->httpClient->request('GET', $url);
        $html = $response->getContent();

        $crawler = new Crawler($html);
        $crawler->filter('.c-products-list__item')->each(function($liCrawler, $i) use ($io, $productRepository){
             $name = $liCrawler->filter('h2.c-product__title')->eq(0)->text();
             $io->text($name);
             $url = 'https://materiel.net' . $liCrawler->filter('a.c-product__link')->eq(0)->attr('href');

             //tchèque si on n'a pas déjà ce produit dans la bdd
             $found = $productRepository->findOneBy(['url' => $url]);
             if ($found){
                 //on s'arrête là pour cette fois, le produit existe déjà !
                 $io->text('Existe déjà !');
                 return;
             }

             $io->text($url);

             $response = $this->httpClient->request('GET', $url);
             $html = $response->getContent();

             $detailCrawler = new Crawler($html);
             $price = $detailCrawler->filter('button[data-product-price-vat-on]')->eq(0)->attr('data-product-price-vat-on');
             $io->text($price);

             $pictureUrl = $detailCrawler->filter('.c-product__thumb img')->eq(0)->attr('data-src');
             $pictureFilename = uniqid();
             file_put_contents('public/img/' . $pictureFilename . ".jpg", file_get_contents($pictureUrl));
             $io->text($pictureUrl);

             $product = new Product();
             $product->setName($name);
             $product->setPrice($price);
             $product->setPicture($pictureFilename);
             $product->setUrl($url);
             $product->setDateCreated(new \DateTime());

             $this->entityManager->persist($product);
        });

        $this->entityManager->flush();

        $io->success('OK finito !');
        return 0;
    }
}
