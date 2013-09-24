<?php
/**
 * This file is part of the {@link http://aksw.org/Projects/Xodx Xodx} project.
 *
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * The PersonController is responsible for all action concerning Persons.
 * This is showing the profile, befriending and maybe more in the future.
 * - editing Profile information
 */
class Xodx_PersonController extends Xodx_ResourceController
{
    /**
     * The cache-array of already queried persons to not query for the same person twice
     */
    private $_persons = array();

    /**
     * A view action to show a person
     */
    public function showAction ($template)
    {
        $bootstrap  = $this->_app->getBootstrap();
        $model      = $bootstrap->getResource('model');
        $request    = $bootstrap->getResource('request');
        $logger     = $bootstrap->getResource('logger');
        $personUri  = $request->getValue('uri', 'get');
        $id         = $request->getValue('id', 'get');
        $controller = $request->getValue('c', 'get');

        // get URI
        if ($id !== null) {
            $personUri = $this->_app->getBaseUri() . '?c=' . $controller . '&id=' . $id;
        }

        $nsFoaf = 'http://xmlns.com/foaf/0.1/';

        $profileQuery = 'PREFIX foaf: <' . $nsFoaf . '> ' . PHP_EOL;
        $profileQuery.= 'SELECT ?depiction ?name ?nick ' .  PHP_EOL;
        $profileQuery.= 'WHERE { ' .  PHP_EOL;
        $profileQuery.= '   <' . $personUri . '> a foaf:Person . ' . PHP_EOL;
        $profileQuery.= 'OPTIONAL {<' . $personUri . '> foaf:depiction ?depiction .} ' . PHP_EOL;
        $profileQuery.= 'OPTIONAL {<' . $personUri . '> foaf:name ?name .} ' . PHP_EOL;
        $profileQuery.= 'OPTIONAL {<' . $personUri . '> foaf:nick ?nick .} ' . PHP_EOL;
        $profileQuery.= '}'; PHP_EOL;

        // TODO deal with language tags
        $contactsQuery = 'PREFIX foaf: <' . $nsFoaf . '> ' . PHP_EOL;
        $contactsQuery.= 'SELECT ?contactUri ?name ?nick ' . PHP_EOL;
        $contactsQuery.= 'WHERE { ' . PHP_EOL;
        $contactsQuery.= '   <' . $personUri . '> foaf:knows ?contactUri . ' . PHP_EOL;
        $contactsQuery.= '   OPTIONAL {?contactUri foaf:name ?name .} ' . PHP_EOL;
        $contactsQuery.= '   OPTIONAL {?contactUri foaf:nick ?nick .} ' . PHP_EOL;
        $contactsQuery.= '}';

        $profile = $model->sparqlQuery($profileQuery);

        if (count($profile) < 1) {
            $linkeddataHelper = $this->_app->getHelper('Saft_Helper_LinkeddataHelper');
            $newStatements = $linkeddataHelper->getResource($personUri);
            if ($newStatements !== null) {
                $logger->info('Import Profile with LinkedDate');

                $modelNew = new Erfurt_Rdf_MemoryModel($newStatements);
                $newStatements = $modelNew->getStatements();

                $profile = array();
                $profile[0] = array(
                    'depiction' => $modelNew->getValue($personUri, $nsFoaf . 'depiction'),
                    'name' => $modelNew->getValue($personUri, $nsFoaf . 'name'),
                    'nick' => $modelNew->getValue($personUri, $nsFoaf . 'nick')
                );
            }
            $friends = $modelNew->getValues($personUri, $nsFoaf . 'knows');

            $knows = array();

            foreach($friends as $friend) {
                $knows[] = array(
                    'contactUri' => $friend['value'],
                    'name' => '',
                    'nick' => ''
                );
            }

        } else {
            $knows = $model->sparqlQuery($contactsQuery);
        }

        $activityController = $this->_app->getController('Xodx_ActivityController');
        $activities = $activityController->getActivities($personUri);

        $news = $this->getNotifications($personUri);

        /* get loged in user */
        $userController = $this->_app->getController('Xodx_UserController');
        $user = $userController->getUser();

        if (false) {
            $template->profileshowLoggedIn = false;
        }
        /* if someone is loged in, show add as Friend, else not */
        $knowsQuery = 'ASK { <' . $user->getPerson() . '> foaf:knows <' . $personUri . '>  }';
        if(
            $user->getName() == 'guest' ||
            $user->getPerson() == $personUri ||
            $model->sparqlQuery($knowsQuery)
        ) {
            $template->profileshowLoggedIn = false;
        } else {
            $template->profileshowLogInUri = $user->getPerson();
            $template->profileshowLoggedIn = true;
        }

        $template->profileshowPersonUri = $personUri;
        $template->profileshowDepiction = $profile[0]['depiction'];
        $template->profileshowName = $profile[0]['name'];
        $template->profileshowNick = $profile[0]['nick'];
        $template->profileshowActivities = $activities;
        $template->profileshowKnows = $knows;
        $template->profileshowNews = $news;
        $template->addContent('templates/profileshow.phtml');

        return $template;
    }

    /**
     * View action for adding a new friend. (This action should be called from a form)
     */
    public function addfriendAction($template)
    {
        $bootstrap = $this->_app->getBootstrap();
        $request = $bootstrap->getResource('request');

        // get URI
        $personUri = $request->getValue('person', 'post');
        $friendUri = $request->getValue('friend', 'post');

        if (Erfurt_Uri::check($personUri) && Erfurt_Uri::check($friendUri)) {
            $personController = $this->_app->getController('Xodx_PersonController');
            $personController->addFriend($personUri, $friendUri);

            //Redirect
            $location = new Saft_Url($this->_app->getBaseUri());

            $location->setParameter('c', 'user');
            $location->setParameter('a', 'home');
            $template->redirect($location);
        } else {
            $template->addContent('templates/error.phtml');
            $template->exception = 'At least one of the given URIs is not valid: personUri="' . $personUri . '", friendUri="' . $friendUri . '".';
        }

        return $template;
    }

    /**
     * Get a DSSN_Foaf_Person object representing the specified person
     *
     * @param $personUri the URI of the person who sould be represented by the returned object
     * @return a DSSN_Foaf_Person object
     */
    public function getPerson ($personUri)
    {
        if (!isset($this->_persons[$psersonUri])) {
            $person = new DSSN_Foaf_Person($personUri);
            $this->_persons[$personUri] = $person;
        }
        return $this->_persons[$personUri];
    }

    /**
     * This method gets the userAccount responsible for a given person.
     *
     * @param $personUri the URI of the person whoes account should be returned
     * @returns Xodx_User account of this person
     */
    public function getUserForPerson ($personUri)
    {
        $model = $this->_app->getBootstrap()->getResource('model');
        $userController = $this->_app->getController('Xodx_UserController');

        $userQuery = 'SELECT ?user' . PHP_EOL;
        $userQuery.= 'WHERE {' . PHP_EOL;
        $userQuery.= '    ?user sioc:account_of <' . $personUri . '>.' . PHP_EOL;
        $userQuery.= '}' . PHP_EOL;
        $userQuery.= 'LIMIT 1' . PHP_EOL;

        $result = $model->sparqlQuery($userQuery);

        $user = $userController->getUser($result[0]['user']);

        return $user;
    }

    /**
     * Add a new contact to the list of friends of a person
     * This is a one-way connection, the contact doesn't has to approve it
     *
     * @param $personUri the URI of the person to whome the contact should be added
     * @param $contactUri the URI of the person who sould be added as friend
     */
    public function addFriend ($personUri, $contactUri)
    {
        $model = $this->_app->getBootstrap()->getResource('model');
        $userController = $this->_app->getController('Xodx_UserController');

        $ldHelper = $this->_app->getHelper('Saft_Helper_LinkeddataHelper');
        if (!$ldHelper->resourceExists($contactUri)) {
            throw new Exception('The WebID of your friend does not exist.');
        }

        // Update WebID
        $model->addStatement($personUri, 'http://xmlns.com/foaf/0.1/knows', array('type' => 'uri', 'value' => $contactUri));

        $nsAair = 'http://xmlns.notu.be/aair#';
        $activityController = $this->_app->getController('Xodx_ActivityController');

        // Add Activity to activity Stream
        $object = array(
            'type' => 'Uri',
            'content' => $contactUri,
            'replyObject' => 'false'
        );
        $activityController->addActivity($personUri, $nsAair . 'MakeFriend', $object);

        // Send Ping to new friend
        $pingbackController = $this->_app->getController('Xodx_PingbackController');
        $pingbackController->sendPing($personUri, $contactUri, 'Do you want to be my friend?');

        // Subscribe to new friend
        $userUri = $userController->getUserUri($personUri);
        $feedUri = $this->getActivityFeedUri($contactUri);
        if ($feedUri !== null) {
            $userController->subscribeToResource ($userUri, $contactUri, $feedUri);
        }
        return;
    }

    /**
     * Returns the feed of the specified $type of the person
     * @param $personUri the URI of the person whoes feed sould be returned
     */
    public function getFeed ($personUri, $type = 'activity')
    {
        $model = $this->_app->getBootstrap()->getResource('model');

        $nsDssn = 'http://purl.org/net/dssn/';

        $feedProp = '';
        if ($type == 'activity') {
            $feedProp = $nsDssn . 'activityFeed';
        } else if ($type == 'sync') {
            $feedProp = $nsDssn . 'syncFeed';
        }

        $feedResult = $model->sparqlQuery(
            'PREFIX atom: <http://www.w3.org/2005/Atom/> ' .
            'PREFIX aair: <http://xmlns.notu.be/aair#> ' .
            'SELECT ?feed ' .
            'WHERE { ' .
            '   <' . $personUri . '> <' . $feedProp . '> ?feed . ' .
            '}'
        );

        return $feedResult[0]['feed'];
    }

    /**
     * Get an array of new notifications for the person
     *
     * @param $personUri the URI of the person whoes notifications should be returned
     */
    public function getNotifications ($personUri)
    {
        $model = $this->_app->getBootstrap()->getResource('model');

        $pingResult = $model->sparqlQuery(
            'PREFIX pingback: <http://purl.org/net/pingback/> ' .
            'SELECT ?ping ?source ?target ?comment ' .
            'WHERE { ' .
            '   <' . $personUri . '> pingback:ping ?ping . ' .
            '   ?ping a                pingback:Item ; ' .
            '         pingback:source  ?source ; ' .
            '         pingback:target  ?target ; ' .
            '         pingback:comment ?comment . ' .
            '} '
        );

        return $pingResult;
    }

    public function editAction()
    {
//This is stuff for Debugging
        echo ("You sent me this:<br>");
        var_dump($_POST);
        echo ("<br>Lenght:");
        echo (count($_POST));
        echo ("ArrayForEach<br><br>");
        foreach ($_POST as $key => $value)
        {
            echo ($key);
            echo ("->");
            echo ($value);
            echo ("<br>");
        }

        echo ("<hr>");
//This is real sourcecode!
        $model = $this->_app->getBootstrap()->getResource('model');
        $applicationController = $this->_app->getController('Xodx_ApplicationController');
        $userId = $applicationController->getUser();
        $userUri = $this->_app->getBaseUri() . '?c=user&id=' . $userId;
        $stringArray = explode("id=", $userUri);
        $name = $stringArray[1];
        $propertyRegex = $this -> loadPropertyRegex();

        $prefixesPrepare = array();
        $valuesPrepare = array();
        $valuesNew = array();
        $newKey;
        $newValue;
        $oldValue;
        $changedADD = array();
        $changedDELETE = array();
        $wrong = array();
        $multiple = $_POST["multiple"];
        unset($_POST["multiple"]);

        //TODO: GET NAME

        $query = "PREFIX foaf: <http://xmlns.com/foaf/0.1/> SELECT ?p ?o WHERE { ?person a foaf:Person. ?person foaf:nick '$name'. ?person ?p ?o }";
        $databaseValues = $model->sparqlQuery($query);

        //prepare $_POST into prefix --> value
        foreach ($_POST as $key => $value)
        {
            $keyArray = explode("_", $key);
            $number = (int)$keyArray[0];
            if ($keyArray[1] == "value")
            {
                $valuesPrepare[$number] = $value;
            }

            if ($keyArray[1] == "prefix")
            {
                $prefixesPrepare[$number] = $value;
            }
        }

        foreach ($prefixesPrepare as $key => $value)
        {
            $valuesNew[$value] = $valuesPrepare[(int)$key];
        }

        echo("Debug valuesNew: ");
        var_dump($valuesNew);
        //echo("<br>Debug prefixesPrepare: ");
        //var_dump($prefixesPrepare);
        echo("<br>");

        foreach ($valuesNew as $key => $value)
        {
            //Reset old values
            $oldValue = "";
            $newKey = $key;

            //find corresponding value in query
            //Searches for equivalent of $newKey
            foreach ($databaseValues as $key => $element)
            {
                $p = $element["p"];
                $o = $element["o"];
                //echo "<br>$p -- $o";
                if (strcmp ($element["p"],  $newKey) == 0)
                {
                    $oldValue = $element["o"];
                }
            }

            if ($value != $oldValue)
            {
                //echo ("$value != $oldValue <br>");

                $rString = $propertyRegex[$newKey];
                //echo ("Regex for $newKey: $rString<br>");
                //$fooo = preg_match($String, $value);
                //var_dump($fooo);
                if (preg_match($String, $value) === true)
                {
                    echo ("Match: $value for $newKey");
                    $changedADD[$newKey] = $value;
                    $changedDELETE[$newKey] = $oldValue;
                }
                else
                {
                    echo ("Wrong Format: $value for $newKey<br>");
                    $wrong[$newKey] == $value;
                }
            }
        }

        echo("Debug Delete: ");
        var_dump($changedDELETE);
        echo("<br>Debug Add: ");
        var_dump($changedADD);

        foreach ($changedDELETE as $key => $value)
        {
            //$keyArray = array('value' => );
            $valueArray = array('type' => 'literal', 'value' => $value);
            echo ("<br>Delete: $userUri, $key, $value");
            $model->deleteStatement($userUri, $key, $valueArray);
        }
        foreach ($changedADD as $key => $value)
        {
            //$keyArray = array('value' => );
            //array('type' => 'uri', 'value' => $newPersonUri)
            $valueArray = array('type' => 'literal', 'value' => $value);
            echo ("<br>Writing: $userUri, $key, $value");
            $model->addStatement($userUri, $key, $valueArray);
        }
    }

    public function profileeditorAction ($template)
    {
        $model = $this->_app->getBootstrap()->getResource('Model');
        $allowedPrefixes = $this->loadPropertiesSingle();

        $multiple = false;

        $applicationController = $this->_app->getController('Xodx_ApplicationController');
        $userId = $applicationController->getUser();
        $userUri = $this->_app->getBaseUri() . '?c=user&id=' . $userId;
        $stringArray = explode("id=", $userUri);
        $name = $stringArray[1];

        $query = "PREFIX foaf: <http://xmlns.com/foaf/0.1/> SELECT ?p ?o WHERE { ?person a foaf:Person. ?person foaf:nick '$name'. ?person ?p ?o }";
        //$query = "PREFIX foaf: <http://xmlns.com/foaf/0.1/> SELECT ?p ?o WHERE { ?person a foaf:Person. ?person foaf:person '$userUri'. ?person ?p ?o }";

        $profiles = $model->sparqlQuery( $query);
        $template->allowedPrefixes = $allowedPrefixes;
        $template->profile = $profiles;
        $template->multiple = $multiple;
        $template->config = $config;

        $template->addContent('templates/profileeditor.phtml');

        return $template;
    }

    /**
     * Quick fix for Erfurt issue #24 (https://github.com/AKSW/Erfurt/issues/24)
     */
    private static function _issueE24fix ($date)
    {
        if (substr($date, 11, 1) != 'T') {
            $dateObj = date_create($date);
            return date_format($dateObj, 'c');
        } else {
            return $date;
        }
    }

    public function loadPropertyRegex()
    {
        $properties = array();
        $config = $this->_app->getBootstrap()->getResource('Config');
        $bothConfigs = explode(",",$config["editor.single"].",".$config["editor.multiple"]);
        $skip = false;

        foreach($bothConfigs as $key => $element)
        {
            if (!$skip)
            {
                $property = $element;
                $skip = true;
            }
            else
            {
                $skip = false;
                $properties[$property] = $this->propertyRegex($element);
            }
        }

        //echo("Dump:<br>");
        //var_dump($properties);
        //var_dump($single);
        //var_dump($multiple);
        //var_dump($config["editor.single"]);
        //var_dump($config["editor.multiple"]);

        return $properties;
    }

    public function propertyRegex($regexName)
    {
        //echo "propertyRegex: $regexName <br>";
        $config = $this->_app->getBootstrap()->getResource('Config');
        $regexString = "regex.".$regexName;
        return $config[$regexString];
    }

    public function loadPropertiesSingle()
    {
        $properties = array();
        $config = $this->_app->getBootstrap()->getResource('Config');
        $single = explode(",",$config["editor.single"]);
        $skip = false;

        foreach($single as $key => $element)
        {
            if (!$skip)
            {
                $properties[] = $element;
                $skip = true;
            }
            else
            {
                $skip = false;
            }
        }
        return $properties;
    }
}
