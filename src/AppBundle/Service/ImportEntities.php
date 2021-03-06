<?php

namespace AppBundle\Service;

use AppBundle\Entity\SoftMain;
use AppBundle\Entity\SoftSeeAlso;
use AppBundle\Entity\Tag;
use Doctrine\DBAL\Connection;
use Symfony\Component\Yaml\Yaml;
use SplFileObject;
use Doctrine\ORM\EntityManagerInterface;



/**
 * Class Service
 * @package AppBundle\Service
 *
 * Special service to import 3 CSV files, which can be find in  app/Resources/datas
 *
 * To import CSV, figure out service command "ImportCommand"
 *
 * 3 files for : softwares (description of each software + list of tags), list of tags, versus (software versus software)
 * manually set, with a description.
 *
 * Important : moulinette use DataBase map, describe in app/config/import.yml
 *
 * CSV file must match with fields and relationships. First row of header must be "Nom"
 *
 */

class ImportEntities
{


    /** @var EntityManagerInterface */
    private $em;
    /**
     * @var Slugification
     */
    private $slugificator;
    /**
     * @var array
     */
    private $errors;
    /**
     * @var mixed
     */
    private $config;

    /**
     * @var SeeAlso
     */
    private $serviceSeeAlso;
    /**
     * @var BoolsAsTags
     */
    private $boolsAsTags;


    public function __construct(EntityManagerInterface $em, Slugification $slugificator, $rootDir, SeeAlso $serviceSeeAlso, BoolsAsTags $boolsAsTags)
    {
        $this->slugificator = $slugificator;
        $this->em = $em;
        $this->errors = array();
        $this->config = Yaml::parse(file_get_contents($rootDir . "/config/import.yml"));
        $this->serviceSeeAlso = $serviceSeeAlso;
        $this->boolsAsTags = $boolsAsTags;
    }

    /**
     * @param string $fileFromConsole
     * @return SplFileObject
     *
     * Receive a file, give an object with parameters to read CSV file (delimiter is ",")
     *
     */

    private function fileInit(string $fileFromConsole): \SplFileObject
    {

        $file = new \SplFileObject($fileFromConsole);

        $file->setFlags(
            SplFileObject::READ_CSV|
            SplFileObject::READ_AHEAD |
            SplFileObject::SKIP_EMPTY |
            SplFileObject::DROP_NEW_LINE
            );

        return $file;
    }

    /**
     * @param string $fileName
     * @param int $line
     * @param $value
     * @param int $column
     *
     * Verify match between expected data (here integer) and effective data, and return error if not matching
     *
     */

    private function checkIfInteger(string $fileName, int $line, $value, int $column)
    {

        if (preg_match("#[0-9]# ", $value) === FALSE && $value != "") {
            array_push($this->errors, "Fichier " . $fileName . ": Line " . $line . " - Column" . $column . ": " . $value . " is expected to be an integer");
        }
    }

    /**
     * @param string $fileName
     * @param int $line
     * @param $value
     * @param int $column
     *
     * Verify correspondance between expected data (here boolean) and effective data, and return error if not matching
     *
     */
    private function checkIfBool(string $fileName, int $line, $value, int $column)
    {

        if (is_bool($value) === FALSE) {
            if (isset($value)) {
                array_push($this->errors, "Fichier " . $fileName . ": Line " . $line . " - Column" . $column . ": " . $value . " is expected to be a boolean");
            }
        }
    }

    /**
     * @param string $value
     * @return bool|null|string
     *
     * To be display online, translate Booleans from true or false to "oui" or "non"
     *
     */
    public function convertToBool(string $value)
    {

        $value = strtolower($value);

        if ($value === "oui") {
            return true;
        }
        if ($value === "non") {
            return false;
        }

        if ($value === "") {
            return null;

        } else {
            return $value;
        }
    }

    /**
     * @param string $file
     * @param array $row
     *
     * Check if owner of files inconveniently duplicate data
     *
     */
    public function searchForDuplicate(string $file, array $row)
    {

        switch ($file) {
            case "import-tags":
                $tag = $this->em->getRepository(Tag::class)
                    ->findOneBy([
                        'name' => $row[0],
                    ]);
                //        array_push($this->errors, "Tag: " . $tag . " is duplicate in file " . $file . ".csv.");
                break;

            case "import-softwares":
                $soft = $this->em->getRepository(SoftMain::class)
                    ->findOneBy([
                        'name' => $row[0],
                    ]);
                //  array_push($this->errors, "Software: " . $soft . " is duplicate in file " . $file . ".csv.");
                break;

        }

    }

    /**
     * @param string $softFile
     * @param string $fileName
     *
     * Check if there are the same number of rows in the config file (import.yml) and in csv file.
     * If not, return an error in console
     *
     */
    public function verifCsv(string $softFile, string $fileName)
    {

        $softEntitiesYml = $this->getConfig()[$fileName]["entities"];
        $splSoftFile = $this->fileInit($softFile);

        while (!$splSoftFile->eof()) {

            $totalFields = 1;
            foreach ($softEntitiesYml as $softEntityYml) {
                if(is_array($softEntityYml["fields"])) {
                    $countField = count($softEntityYml["fields"]);
                } else {
                    $countField = 0;
                }
                $totalFields += $countField;
            }

            foreach ($splSoftFile as $rowFile) {

                if (is_array($rowFile) && count($rowFile) !== $totalFields) {
                    array_push($this->errors, "Nombre de colonne incorrect dans le fichier : " . $fileName . ".csv ; attendu ".$totalFields." versus ".count($rowFile));
                } else {

                        $stillExists = $this->searchForDuplicate($fileName, $rowFile);
                        if (null === $stillExists) {

                            $line = 1;
                            foreach ($splSoftFile as $row) {

                                $column = 1;
                                if (strtolower($row[0]) !== "ok") {
                                } else {


                                    foreach ($softEntitiesYml as $entity) {

                                        //parcourt les proprietés de chaque entity
                                        foreach ($entity["fields"] as $property) {

                                            switch ($property) {

                                                case "list-tag":
                                                    break;

                                                case "string":
                                                    break;

                                                case "boolean":

                                                    $this->checkIfBool($fileName, $line, $this->convertToBool($row[$column]), $column);
                                                    break;

                                                case "integer":

                                                    $this->checkIfInteger($fileName, $line, $row[$column], $column);
                                                    break;

                                            }
                                            $column++;
                                        }
                                    }

                                }
                                $line++;
                            }
                        }

                }
            }
        }
    }

    /**
     * @param string $softFile
     * @param string $type
     *
     * Final import function : give location of file (not yet an object)
     * with type of topic (tag or software or versus),
     * after verifications
     *
     */
    public function import(string $softFile, string $type)
    {

        $softEntitiesYml = $this->getConfig()[$type]["entities"];
        $entityKeys = array_keys($softEntitiesYml);
        $splSoftFile = $this->fileInit($softFile);

        while (!$splSoftFile->eof()) {
            foreach ($splSoftFile as $row) {

                if (strtolower($row[0]) !== "ok") {
                } else {

                    array_shift($row);
                    $convertedData = [];
                    $caseImport = 0;
                    $i = 0;
                    $j = 0;
                    $eachEntity = [];

                    // Explore each entity to add data
                    foreach ($softEntitiesYml as $entity) {
                        $myClass = "AppBundle\\Entity\\" . $entityKeys[$i];
                        $eachEntity[$i] = new $myClass();
                        $listFields = array_keys($entity["fields"]);

                        // Explore each properties of each entities
                        foreach ($entity["fields"] as $property) {

                            if ($property === "boolean") {
                                $convertedData[$caseImport] = $this->convertToBool($row[$caseImport]);
                            } elseif ($property === "integer") {
                                $convertedData[$caseImport] = (int)$row[$caseImport];
                            } else {
                                $convertedData[$caseImport] = $row[$caseImport];
                            }

                            if (is_array($property) && count($property) === 3) {
                                $soft = $this->em->getRepository(SoftMain::class)
                                    ->findOneBy([
                                        'name' => $convertedData[$caseImport],
                                    ]);

                                if (!empty($soft)) {
                                    $set = "set" . ucfirst($listFields[$j]);
                                    $eachEntity[$i]->$set($soft);
                                    $add = "add" . ucfirst($property["inversedby"]);
                                    $soft->$add($eachEntity[$i]);
                                    $this->em->persist($soft);
                                }
                            } elseif ($property === "list-tag") {
                                $tags = explode("#", $convertedData[$caseImport]);
                                foreach ($tags as $tag) {
                                    $currentTag = $this->em->getRepository(Tag::class)->findOneBy(['name' => $tag,]);
                                    if (!empty($currentTag)) {
                                        $eachEntity[$i]->addTag($currentTag);
                                        $currentTag->addSoftMain($eachEntity[$i]);
                                        $this->em->persist($currentTag);
                                    }
                                }

                            } else {
                                $eachSetter = "set" . ucfirst($listFields[$j]);
                                $eachEntity[$i]->$eachSetter($convertedData[$caseImport]);
                            }
                            $j++;
                            $caseImport++;
                        }
                        $j = 0;
                        $i++;
                    }
                    //csv reading end
                    // add Links for each entities
                    $k = 0;
                    $slug = $this->slugificator->slugFactory($row[0]);

                    foreach ($softEntitiesYml as $entity) {

                        //TODO: improve to manage other relations

                        if ($entity["links"]["relation"] === "Many-to-Many") {
                            $eachSetterLink = "add" . $entityKeys[$k];
                            $eachSource = "AppBundle\\Entity\\" . $entity["links"]["source"];
                            $eachSource->$eachSetterLink($eachEntity[$k]);
                        }
                        //upgrade: $eachEntity[0] can be change by an automatic
                        if ($entity["links"]["relation"] === "One-to-One") {
                            $eachSetterLink = "set" . $entityKeys[$k];
                            $eachEntity[0]->$eachSetterLink($eachEntity[$k]);
                        }

                        if ($entity["slugExceptions"]["slug"] === "yes") {
                            $mySlugSetter = "setSlug";

                            $eachEntity[0]->$mySlugSetter("$slug");
                        }
                        if ($entity["slugExceptions"]["logo"] === "yes") {
                            $mySlugLogoUrlSetter = "setLogoUrl";
                            $eachEntity[0]->$mySlugLogoUrlSetter("assets/img/logo/" . $slug . ".png");
                        }
                        $k++;
                    }
                    // persist for each entities

                    foreach ($eachEntity as $finalEntity) {
                        $this->em->persist($finalEntity);
                    }

                    $this->em->flush();

                }
            }

        }
    }

    /**
     * @return array
     *
     * Get errors to be display in console
     * See Console Command service in AppBundle/Command/ImportCommand.php
     *
     */
    public
    function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return mixed
     */
    public
    function getConfig()
    {
        return $this->config;
    }

    /**
     * @param Connection $connection
     * @param $dbName
     *  // TODO : better explanation of this function
     * This function has to be implemented inside a transaction with a commit at the end
     */
    public
    function deleteAllContent(Connection $connection, $dbName)
    {

        foreach ($this->getConfig()["table-names"] as $tableName) {
            $connection->query("DELETE FROM " . $tableName . ";");
            // Beware of ALTER TABLE here--it's another DDL statement and will cause
            // an implicit commit.

        }
    }

    public function addSeeAlsoBySoftwares() {
        $repoSoft = $this->em->getRepository(SoftMain::class);
        $softwares = $repoSoft->findAll();
        $repoSeeAlso = $this->em->getRepository(SoftSeeAlso::class);
        foreach($softwares as $software) {

            $addSeeAlso = new SoftSeeAlso();
            $addSeeAlso->setSoftMain($software);
            $listSeeAlso = $this->serviceSeeAlso->getListOfSameSoftwares($software, 6);
            $bools = $this->boolsAsTags->getBoolsBySoftware($software);
            $addSeeAlso->setBooleans($bools);
            $addSeeAlso->setSoftSeeAlsoArray($listSeeAlso);
            $this->em->persist($addSeeAlso);
        }
        $this->em->flush();
    }

}