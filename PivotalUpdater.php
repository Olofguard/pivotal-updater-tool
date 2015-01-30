#!/usr/local/Cellar/php54/5.4.27/bin/php

<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application;

/**
 * Class PivotalUpdater
 *
 * This is essentially a tool designed to be used as a git hook. The hook will inspect merges into develop and
 * staging using the post-merge git hook. If it's an MR to develop the hook looks to see if a story was finished and
 * sets the status to finished on Pivotal Tracker. If it's an MR to staging (develop > staging) the hook
 * will inspect all merge requests coming in to staging in that particular merge. If one of the merges was a completed
 * story, which has been flagged as finished at this point, the hook will update the story's status to delivered.
 *
 * @copyright  Indatus 2014
 * @author     Damien Russell <drussell@indatus.com>
 */
class PivotalUpdater extends Application
{

    /** @var InputInterface $input */
    protected $input;


    /** @var OutputInterface $output */
    protected $output;


    /** @var string $baseUrl the base url for the pivotal endpoints */
    protected $baseUrl = "https://www.pivotaltracker.com/services/v5/projects/";


    /** @var string $apiToken The api token. */
    protected $apiToken = 'YOUR_API_KEY';


    /** @var array An array of piviotal projects. */
    protected $projects = [
        'PROJECT_1' => 'YOUR_PROJECT_ID',
    ];


    /**
     * Construct
     */
    public function construct()
    {
        parent::__construct('Pivotal Updater Tool', '1.0.0');
    }


    /**
     * This method is where all the work occurs.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->output->writeln(
            '<fg=black;options=bold;bg=white>Pivotal Updater Tool</fg=black;options=bold;bg=white>'
        );

        $this->output->writeln("<info>Inspecting last commit message...</info>");

        $message = $this->getLastCommitMessage();

        if ($this->toStaging($message)) {
            $this->output->writeln("<info>Inspecting merge requests in merge...</info>");

            $merges = $this->getMergeSha1s();

            $count = 0;
            foreach ($merges as $merge) {
                $this->output->writeln("<info>{$merge}</info>");

                $commitMessage = $this->getCommitMessageBySha1($merge);

                if ($this->isComplete($commitMessage)) {
                    $story = $this->getStoryId($commitMessage);
                    $this->output->writeln('Delivering Story ' . $story . '...');
                    $this->updateStory($story, $this->projects['PROJECT_1'], ['current_state' => 'delivered']);
                }
            }

            $this->output->writeln('<info>Done...</info>');
        } elseif ($this->toDevelop($message)) {
            $story = $this->getStoryId($message);

            if($this->isComplete($message)) {
                $this->updateStory($story, $this->projects['PROJECT_1'], ['current_state' => 'finished']);
            }
        }

        return 0;
    }


    /**
     * Get the last commit message.
     *
     * @return array The body of the commit in the form of an array of strings.
     */
    public function getLastCommitMessage()
    {
        $output = [];
        $return = 0;

        exec('git show --format="%B"', $output, $return);

        return $output;
    }


    /**
     * Get all the MRs in the last MR.
     *
     * @return array An array of the merge SHA1s in the last merge.
     */
    public function getMergeSha1s()
    {
        $output = [];
        $return = 0;

        exec('git log HEAD~1..develop --merges --format="%h"', $output, $return);

        return $output;
    }


    /**
     * Get the commit message for a particular commit based on it's SHA1
     *
     * @param string $sha1
     *
     * @return array The body of the MR in the form of an array of strings.
     */
    public function getCommitMessageBySha1($sha1)
    {
        $output = [];
        $return = 0;

        exec("git show {$sha1} --format='%B'", $output, $return);

        return $output;
    }


    /**
     * Determine if the MR is to develop.
     *
     * @param string $commitMessage The message of the commit like (Merging branch '123-feature' to 'develop')
     *
     * @return bool
     */
    public function toDevelop($commitMessage)
    {
        if (strpos($commitMessage[0],'develop') !== false) {
            return true;
        }

        return false;
    }


    /**
     * Determine if the MR is to staging.
     *
     * @param string $commitMessage The message of the commit like (Merging branch '123-feature' to 'develop')
     *
     * @return bool
     */
    public function toStaging($commitMessage)
    {
        if (strpos($commitMessage[0],'staging') !== false) {
            return true;
        }

        return false;
    }


    /**
     * Get story id from the commit message.
     *
     * Takes commit messages like Merging branch '123-feature' to 'develop' and grabs the story id
     * out of the commit message. Returns null if a story can't be found.
     *
     * @param string $commitMessage
     *
     * @return null|int
     */
    public function getStoryId($commitMessage)
    {
        $result = preg_grep("/[0-9]+\-[a-zA-Z-]+[^\s']/", $commitMessage);

        if (count($result) == 1) {

            $matches = [];

            preg_match("/[0-9]+\-[a-zA-Z-]+[^\s']/", $result[0], $matches);

            preg_match("/[0-9]+/", $matches[0], $matches);

            return $matches[0];
        }

        return null; // no story number present, may be a hotfix-branch
    }

    /**
     * Determine if the commit message has @Complete in it, symbolizing
     * a finished story in Pivotal Tracker
     *
     * @param string $commitMessage the body of the commit
     *
     * @return bool
     */
    public function isComplete($commitMessage)
    {
        if (is_array($commitMessage)) {
            $complete = preg_grep("/(@Complete)/", $commitMessage);

            return count($complete) == 1;
        }

        return strpos($commitMessage, 'Complete') !== false;
    }


    /**
     * Update a story for a given project, using the provided data
     *
     * @param int $storyId The id of the story
     * @param int $projectId The id fo the project
     * @param array $data The data to update the story with
     *
     * @return mixed
     */
    public function updateStory($storyId, $projectId, array $data)
    {
        $ch = curl_init();

        $url = $this->baseUrl . "{$projectId}/stories/{$storyId}";

        // set url
        curl_setopt($ch, CURLOPT_URL, $url);

        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // set the request method
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");

        // set custom headers for pivotaltracker in this case the api token
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TrackerToken: {$this->apiToken}",
        ));

        // set the post fields for the request
        curl_setopt($ch, CURLOPT_POST, 1);

        // ['current_state' => 'finished']
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

        // $output contains the output string
        $output = curl_exec($ch);

        // close curl resource to free up system resources
        curl_close($ch);
        return $output;
    }
}

$console = new PivotalUpdater();
$console->run();

