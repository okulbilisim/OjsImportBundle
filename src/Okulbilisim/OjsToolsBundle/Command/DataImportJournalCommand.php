<?php

namespace Okulbilisim\OjsToolsBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Okulbilisim\OjsToolsBundle\Helper\StringHelper;
use Ojs\JournalBundle\Entity\Article;
use Ojs\JournalBundle\Entity\Institution;
use Okulbilisim\LocationBundle\Entity\Country;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Input\InputArgument;


use Ojs\UserBundle\Entity\User;
use Ojs\JournalBundle\Entity\Journal;
use Ojs\JournalBundle\Entity\Author;
use Ojs\UserBundle\Entity\UserJournalRole;

/**
 * Class DataImportJournalCommand
 * @package Okulbilisim\OjsToolsBundle\Command
 */
class DataImportJournalCommand extends ContainerAwareCommand
{
    /**
     * @var array PKPOjs roles data.
     */
    protected $roles = [
        'ROLE_ID_SITE_ADMIN' => "0x00000001",
        'ROLE_ID_SUBMITTER' => "0x00000002",
        'ROLE_ID_JOURNAL_MANAGER' => "0x00000010",
        'ROLE_ID_EDITOR' => "0x00000100",
        'ROLE_ID_SECTION_EDITOR' => '0x00000200',
        'ROLE_ID_LAYOUT_EDITOR' => '0x00000300',
        'ROLE_ID_REVIEWER' => "0x00001000",
        'ROLE_ID_COPYEDITOR' => '0x00002000',
        'ROLE_ID_PROOFREADER' => "0x00003000",
        'ROLE_ID_AUTHOR' => "0x00010000",
        'ROLE_ID_READER' => '0x00100000',
        'ROLE_ID_SUBSCRIPTION_MANAGER' => "0x00200000",
    ];
    /**
     * @var array Ojs roles data map
     */
    protected $rolesMap = [
        'ROLE_ID_SITE_ADMIN' => "ROLE_SUPER_ADMIN",
        'ROLE_ID_SUBMITTER' => "ROLE_USER",
        'ROLE_ID_JOURNAL_MANAGER' => "ROLE_JOURNAL_MANAGER",
        'ROLE_ID_EDITOR' => "ROLE_EDITOR",
        'ROLE_ID_SECTION_EDITOR' => 'ROLE_SECTION_EDITOR',
        'ROLE_ID_LAYOUT_EDITOR' => 'ROLE_LAYOUT_EDITOR',
        'ROLE_ID_REVIEWER' => "ROLE_REVIEWER",
        'ROLE_ID_COPYEDITOR' => 'ROLE_COPYEDITOR',
        'ROLE_ID_PROOFREADER' => "ROLE_PROOFREADER",
        'ROLE_ID_AUTHOR' => "ROLE_AUTHOR",
        'ROLE_ID_READER' => 'ROLE_READER',
        'ROLE_ID_SUBSCRIPTION_MANAGER' => "ROLE_SUBSCRIPTION_MANAGER",
    ];

    /**
     * @var array PKPOjs database
     */
    protected $database = [
        'driver' => 'pdo_mysql',
        'user' => 'root',
        'password' => '',
        'host' => 'localhost',
        'dbname' => 'dergipark',
    ];

    /** @var  Connection */
    protected $connection;

    /** @var  EntityManager */
    protected $em;

    /** @var  OutputInterface */
    protected $output;

    /** @var  TranslationRepository */
    protected $translationRepository;

    /**
     * Command configuration.
     */
    protected function configure()
    {
        gc_collect_cycles();
        $this
            ->setName('ojs:import:journal')
            ->setDescription('Import journals')
            ->addArgument(
                'JournalId', InputArgument::REQUIRED, 'Journal ID at ');
        $roles = [];
        /**
         * we must convert hex data to decimal for database equality.
         */
        foreach ($this->roles as $k => $r) {
            $roles[hexdec($r)] = $k;
        }
        $this->roles = $roles;


    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connectionFactory = $this->getContainer()->get('doctrine.dbal.connection_factory');
        $this->connection = $connectionFactory->createConnection($this->database);
        unset($connectionFactory);

        $this->em = $this->getContainer()->get("doctrine.orm.entity_manager");
        $this->em->getConnection()->getConfiguration()->getSQLLogger(null);

        $this->translationRepository = $this->em->getRepository('Gedmo\\Translatable\\Entity\\Translation');

        $kernel = $this->getContainer()->get('kernel');
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $this->output = $output;

        // Journal Old ID
        $id = $input->getArgument('JournalId');


        try {

            /**
             * @var array $journal_raw Journal main data.
             * Its contain `journal_id`,`path`,`seq`,`primary_locale`,`enabled` fields
             */
            $journal_raw = $this->connection->fetchAll("SELECT * FROM journals where journal_id={$id} limit 1;");


            /**
             * @var array $journal_details Journal detailed data as stored key-value.
             * Its contain `journal_id`,`locale`,`setting_name`,`setting_value`,`setting_type` fields
             */
            $journal_details = $this->connection->fetchAll(" select
                  locale,setting_name,setting_value
                from
                  journal_settings where journal_id = {$id} ");

            /**
             * I remake array groupped by locale
             */
            $journal_detail = [];
            foreach ($journal_details as $_journal_detail) {
                if ($_journal_detail['locale'] == 'tr_TR' || empty($_journal_detail['locale']))
                    $journal_detail[$_journal_detail['setting_name']] = $_journal_detail['setting_value'];
            }


            /**
             * Journal Create
             */
            $journal = $this->createJournal($journal_detail, $journal_raw);
            $output->writeln("<info>Journal created.</info>");

            $this->connectJournalUsers($journal, $output,$id);

            $output->writeln("\nUsers added.");
            /*
            * Journal Issues
            */

            /*
             * Issue Articles
             */


            /*
             * Article Files
             */
            $this->createArticles($output, $journal, $id);
            $output->writeln("\nArticles added.");


        } catch (\Exception $e) {
            echo $e->getMessage();
        }


    }

    private function saveArticleData($_article, Journal $journal)
    {
        $_article_settings = $this->connection->fetchAll("SELECT * FROM article_settings WHERE article_id={$_article['article_id']}");
        $article = new Article();
        $article_settings = [];
        foreach ($_article_settings as $as) {
            if ($as['locale'] == '') {
                $article_settings['default'][$as['setting_name']] = $as['setting_value'];
            } else {
                $article_settings[$as['locale']][$as['setting_name']] = $as['setting_value'];
            }
        }


        $article->setJournal($journal);
        isset($article_settings['default']['pub-id::doi']) && $article->setDoi($article_settings['default']['pub-id::doi']);
        if ($_article['pages']) {
            $pages = explode('-', $_article['pages']);
            isset($pages[0]) && $article->setFirstPage((int)$pages[0] == 0 && !empty($pages[0]) ? StringHelper::roman2int($pages[0]) : $pages[0]);
            isset($pages[1]) && $article->setLastPage((int)$pages[1] == 0 && !empty($pages[1]) ? StringHelper::roman2int($pages[1]) : $pages[1]);

        }
        $username = $this->connection->fetchColumn("SELECT username FROM users WHERE user_id='{$_article['user_id']}'");

        $user = $this->em->getRepository('OjsUserBundle:User')->findOneBy(['username' => $username]);

        if ($user) {
            $article->setSubmitterId($user->getId());
        }
        $article->setSubmissionDate(new \DateTime($_article['date_submitted']));
        $article->setStatus($_article['status']); //@todo check
        $article->setIsAnonymous($_article['hide_author'] ? true : false);

        unset($article_settings['default']);

        //find primary languages
        if (count($article_settings) < 1) {
            return false;
        }

        $sizeof = array_map(function ($a) {
            return count($a);
        }, $article_settings);
        $defaultLocale = array_search(max($sizeof), $sizeof);

        $article->setPrimaryLanguage($defaultLocale);

        isset($article_settings[$defaultLocale]['title'])
        && $article->setTitle($article_settings[$defaultLocale]['title']);
        isset($article_settings[$defaultLocale]['abstract'])
        && $article->setAbstract($article_settings[$defaultLocale]['abstract']);


        unset($article_settings[$defaultLocale]);

        foreach ($article_settings as $locale => $value) {
            isset($value['title']) && $this->translationRepository
                ->translate($article, 'title', $locale, $value['title']);
            isset($value['abstract']) && $this->translationRepository
                ->translate($article, 'abstract', $locale, $value['abstract']);
            isset($value['subject']) && $this->translationRepository
                ->translate($article, 'subjects', $locale, $value['subject']);
        }


        $this->em->persist($article);
        $this->em->flush();
    }

    protected function createJournal($journal_detail, $journal_raw)
    {
        $journal = new Journal();
        isset($journal_detail['title']) && $journal->setTitle($journal_detail['title']);
        isset($journal_detail['abbreviation']) && $journal->setTitleAbbr($journal_detail['abbreviation']);
        isset($journal_detail['description']) && $journal->setDescription($journal_detail['description']);
        isset($journal_detail['homeHeaderTitle']) && $journal->setSubtitle($journal_detail['homeHeaderTitle']);
        isset($journal_detail['printIssn']) && $journal->setIssn($journal_detail['printIssn']);
        isset($journal_detail['onlineIssn']) && $journal->setEissn($journal_detail['onlineIssn']);
        isset($journal_detail['onlineIssn']) && $journal->setEissn($journal_detail['onlineIssn']);
        isset($journal_raw['path']) && $journal->setPath($journal_raw['path']);
        // TODO setPeriod
        // $journal->setPeriod();
        isset($journal_raw['path']) && $journal->setSlug($journal_raw['path']);
        $journal->setStatus(1);
        isset($journal_detail['publisherUrl']) && $journal->setUrl($journal_detail['publisherUrl']);
        isset($journal_detail['searchKeywords']) && $journal->setTags($journal_detail['searchKeywords']);
        //$journal->setCountryId();

        if (isset($journal_detail['publisherInstitution'])) {
            /**
             * Institution
             */
            /** @var Institution $institution */
            $institution = $this->em->getRepository('OjsJournalBundle:Institution')->findOneBy(['name' => $journal_detail['publisherInstitution']]);
            if ($institution) {
                $journal->setInstitution($institution);
            }
        }

        $this->em->persist($journal);
        $this->em->flush();
        $this->em->clear();
        return $journal;
    }

    protected function connectJournalUsers(Journal $journal, $output,$old_journal_id)
    {
        /*
             * Journal users
             */
        $journal_users = $this->connection->fetchAll("select distinct user_id,role_id from roles where journal_id={$old_journal_id} group by user_id order by user_id asc");
        $users_count = $this->connection->fetchArray("select count(*) as c from (select distinct user_id from roles where journal_id={$old_journal_id} group by user_id order by user_id asc) b;");

        $userProgress = new ProgressBar($output, $users_count[0]);
        $userProgress->setMessage("Adding users");
        $userProgress->setFormat('<info>%message%</info> <comment;options=bold>%current%/%max%</comment;options=bold> <fg=white;bg=black>[%bar%]</fg=white;bg=black> %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $userProgress->setBarCharacter("≈");
        $userProgress->setProgressCharacter("∂");
        $userProgress->setEmptyBarCharacter(" ");
        $userProgress->start();
        foreach ($journal_users as $journal_user) {
            // all relations disconnecting if i use em->clear. I refind journal for fix this issue
            $journal = $this->em->find('OjsJournalBundle:Journal', $journal->getId());
            $user = $this->createUser($journal_user);
            /*
             * User roles with journal
             */
            $this->addJournalRole($user, $journal, $journal_user['role_id']);
            /*
             * Add author data
             */
            $this->saveAuthorData($user);
            $userProgress->advance();

            //performance is suchs if i dont use em->clear.
            $this->em->clear();
        }
        $userProgress->finish();
    }

    protected function createUser($journal_user)
    {
        $user = $this->connection->fetchAll('SELECT * FROM users WHERE user_id=' . $journal_user['user_id'] . ' LIMIT 1;')[0];

        $usercheck = $this->em->getRepository('OjsUserBundle:User')->findOneBy(['username' => $user['username']]);
        $user_entity = $usercheck ? $usercheck : new User();
        isset($user['first_name']) && $user_entity->setFirstName($user['first_name']);
        isset($user['middle_name']) && $user_entity->setFirstName($user_entity->getFirstName() . ' ' . $user['middle_name']);
        isset($user['username']) && $user_entity->setUsername($user['username']);
        isset($user['last_name']) && $user_entity->setLastName($user['last_name']);
        isset($user['email']) && $user_entity->setEmail($user['email']);
        isset($user['gender']) && $user_entity->setGender($user['gender']);
        isset($user['initials']) && $user_entity->setInitials($user['initials']);
        isset($user['url']) && $user_entity->setUrl($user['url']);
        isset($user['phone']) && $user_entity->setPhone($user['phone']);
        isset($user['fax']) && $user_entity->setFax($user['fax']);
        isset($user['mailing_address']) && $user_entity->setAddress($user['mailing_address']);
        isset($user['billing_address']) && $user_entity->setBillingAddress($user['billing_address']);
        isset($user['billing_address']) && $user_entity->setBillingAddress($user['billing_address']);
        isset($user['locales']) && $user_entity->setLocales(serialize(explode(':', $user['locales'])));
        $user_entity->generateApiKey();
        isset($user['salutation']) && $user_entity->setTitle($user['salutation']);
        if ($user['disabled'] == 1) {
            $user_entity->setIsActive(false);
            $user_entity->setDisableReason(isset($user['disable_reason']) && $user['disable_reason']);
            $user_entity->setStatus(0);
        }
        $country = $this->em->getRepository('OkulbilisimLocationBundle:Country')->findOneBy(['iso_code' => $user['country']]);
        if ($country instanceof Country)
            $user_entity->setCountry($country);
        $this->em->persist($user_entity);
        $this->em->flush();
        return $user_entity;
    }

    protected function addJournalRole( $user,$journal, $role_id)
    {
        $user_role = new UserJournalRole();
        $user_role->setUser($user);
        $user_role->setJournal($journal);

        $role = $this->em->getRepository('OjsUserBundle:Role')->findOneBy([
            'role' => $this->rolesMap[$this->roles[$role_id]]]);
        $user_role->setRole($role);
        $this->em->persist($user_role);
        $this->em->flush();
    }

    protected function saveAuthorData($user)
    {
        $author = new Author();
        $author->setFirstName($user->getFirstName());
        $author->setLastName($user->getLastName());
        //$author->setMiddleName($user['middle_name']);
        $author->setEmail($user->getEmail());
        $author->setInitials($user->getInitials());
        $author->setTitle($user->getTitle());
        $author->setAddress($user->getAddress());
        $author->setBillingAddress($user->getBillingAddress());
        $author->setLocales($user->getLocales());
        $author->setUrl($user->getUrl());
        $author->setPhone($user->getPhone());
        $author->setCountry($user->getCountry());
        $author->setUser($user);
        $this->em->persist($author);
        $this->em->flush();
        return $author;
    }

    protected function createArticles($output, Journal $journal, $old_journal_id)
    {
        $articles = $this->connection->fetchAll("SELECT * FROM articles WHERE journal_id=$old_journal_id");
        $articleProgress = new ProgressBar($output, count($articles));

        $articleProgress->setMessage("Adding articles");
        $articleProgress->setFormat('<info>%message%</info> <comment;options=bold>%current%/%max%</comment;options=bold> <fg=white;bg=black>[%bar%]</fg=white;bg=black> %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $articleProgress->setBarCharacter("≈");
        $articleProgress->setProgressCharacter("∂");
        $articleProgress->setEmptyBarCharacter(" ");
        $articleProgress->start();
        foreach ($articles as $_article) {
            /** @var Journal $journal */
            $journal = $this->em->find('OjsJournalBundle:Journal', $journal->getId());
            $this->saveArticleData($_article, $journal);
            $this->em->clear();
            $articleProgress->advance();
        }
        $articleProgress->finish();
    }
}