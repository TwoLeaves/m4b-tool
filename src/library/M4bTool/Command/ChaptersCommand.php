<?php


namespace M4bTool\Command;


use Exception;
use M4bTool\Audio\Chapter;
use M4bTool\Audio\Silence;
use M4bTool\Audio\Tag;
use M4bTool\Audio\Tag\AdjustTooLongChapters;
use M4bTool\Audio\Tag\TagImproverComposite;
use M4bTool\M4bTool\Audio\Tag\ChaptersFromEpub;
use M4bTool\Parser\MusicBrainzChapterParser;
use Psr\Cache\InvalidArgumentException;
use Sandreas\Time\TimeUnit;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ChaptersCommand extends AbstractCommand
{
    const OPTION_MERGE_SIMILAR = "merge-similar";

    const OPTION_FIND_MISPLACED_CHAPTERS = "find-misplaced-chapters";
    const OPTION_FIND_MISPLACED_OFFSET = "find-misplaced-offset";
    const OPTION_FIND_MISPLACED_TOLERANCE = "find-misplaced-tolerance";

    const OPTION_CHAPTER_PATTERN = "chapter-pattern";
    const OPTION_CHAPTER_REPLACEMENT = "chapter-replacement";
    const OPTION_CHAPTER_REMOVE_CHARS = "chapter-remove-chars";
    const OPTION_FIRST_CHAPTER_OFFSET = "first-chapter-offset";
    const OPTION_LAST_CHAPTER_OFFSET = "last-chapter-offset";

    const OPTION_NO_CHAPTER_NUMBERING = "no-chapter-numbering";
    const OPTION_NO_CHAPTER_IMPORT = "no-chapter-import";
    const OPTION_ADJUST_BY_SILENCE = "adjust-by-silence";

    const OPTION_EPUB = "epub";
    const OPTION_EPUB_IGNORE_CHAPTERS = "epub-ignore-chapters";

    /**
     * @var MusicBrainzChapterParser
     */
    protected $mbChapterParser;

    /**
     * @var SplFileInfo
     */
    protected $filesToProcess;
    protected $silenceDetectionOutput;
    /**
     * @var Silence[]
     */
    protected $silences = [];
    protected $chapters = [];
    protected $outputFile;


    protected function configure()
    {
        parent::configure();
        $this->setDescription('Adds chapters to m4b file');         // the short description shown while running "php bin/console list"
        $this->setHelp('Can add Chapters to m4b files via different types of inputs'); // the full command description shown when running the command with the "--help" option

        $this->addOption(static::OPTION_EPUB, null, InputOption::VALUE_OPTIONAL, "use this epub to extract chapter names", false);
        $this->addOption(static::OPTION_EPUB_IGNORE_CHAPTERS, null, InputOption::VALUE_OPTIONAL, sprintf("Chapter indexes that are present in the epub file but not in the audiobook (0 for the first, -1 for the last - e.g. --%s=0,1,-1 would remove the first, second and last epub chapter)", static::OPTION_EPUB_IGNORE_CHAPTERS), "");

        $this->addOption(static::OPTION_MUSICBRAINZ_ID, "m", InputOption::VALUE_REQUIRED, "musicbrainz id so load chapters from");
        $this->addOption(static::OPTION_MERGE_SIMILAR, "s", InputOption::VALUE_NONE, "merge similar chapter names");
        $this->addOption(static::OPTION_OUTPUT_FILE, static::OPTION_OUTPUT_FILE_SHORTCUT, InputOption::VALUE_OPTIONAL, "write chapters to this output file", "");
        $this->addOption(static::OPTION_ADJUST_BY_SILENCE, null, InputOption::VALUE_NONE, "will try to adjust chapters of a file by silence detection and existing chapter marks");

        $this->addOption(static::OPTION_FIND_MISPLACED_CHAPTERS, null, InputOption::VALUE_OPTIONAL, "mark silence around chapter numbers that where not detected correctly, e.g. 8,15,18", "");
        $this->addOption(static::OPTION_FIND_MISPLACED_OFFSET, null, InputOption::VALUE_OPTIONAL, "mark silence around chapter numbers with this offset seconds maximum", 120);
        $this->addOption(static::OPTION_FIND_MISPLACED_TOLERANCE, null, InputOption::VALUE_OPTIONAL, "mark another chapter with this offset before each silence to compensate ffmpeg mismatches", -4000);


        $this->addOption(static::OPTION_NO_CHAPTER_NUMBERING, null, InputOption::VALUE_NONE, "do not append chapter number after name, e.g. My Chapter (1)");
        $this->addOption(static::OPTION_NO_CHAPTER_IMPORT, null, InputOption::VALUE_NONE, "do not import chapters into m4b-file, just create chapters.txt");

        $this->addOption(static::OPTION_CHAPTER_PATTERN, null, InputOption::VALUE_OPTIONAL, "regular expression for matching chapter name", "/^[^:]+[1-9][0-9]*:[\s]*(.*),.*[1-9][0-9]*[\s]*$/i");
        $this->addOption(static::OPTION_CHAPTER_REPLACEMENT, null, InputOption::VALUE_OPTIONAL, "regular expression replacement for matching chapter name", "$1");
        $this->addOption(static::OPTION_CHAPTER_REMOVE_CHARS, null, InputOption::VALUE_OPTIONAL, "remove these chars from chapter name", "„“”");
        $this->addOption(static::OPTION_FIRST_CHAPTER_OFFSET, null, InputOption::VALUE_OPTIONAL, "milliseconds to add after silence on chapter start", 0);
        $this->addOption(static::OPTION_LAST_CHAPTER_OFFSET, null, InputOption::VALUE_OPTIONAL, "milliseconds to add after silence on chapter start", 0);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void|null
     * @throws InvalidArgumentException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initExecution($input, $output);
        $this->initParsers();
        $this->loadFileToProcess();

        $silenceMinLength = new TimeUnit((int)$this->input->getOption(static::OPTION_SILENCE_MIN_LENGTH));
        if ($silenceMinLength->milliseconds() < 1) {
            throw new Exception("%s must be a positive integer value", static::OPTION_SILENCE_MIN_LENGTH);
        }

        if ($input->getOption(static::OPTION_EPUB) !== false) {
            $this->handleEpub(
                $input->getOption(static::OPTION_EPUB),
                $this->metaHandler->detectSilences($this->filesToProcess, $silenceMinLength)
            );
            return;
        }

        $this->silences = $this->metaHandler->detectSilences($this->filesToProcess, $silenceMinLength);

        $parsedChapters = [];

        if ($this->input->getOption(static::OPTION_ADJUST_BY_SILENCE)) {
            $this->loadOutputFile();
            if ($this->optForce && file_exists($this->outputFile)) {
                unlink($this->outputFile);
            }
            if (!copy($this->argInputFile, $this->outputFile)) {
                throw new Exception("Could not copy " . $this->argInputFile . " to " . $this->outputFile);
            }
            $tag = $this->metaHandler->readTag(new SplFileInfo($this->outputFile));
            $parsedChapters = $tag->chapters;
        } else if ($this->mbChapterParser) {
            $mbXml = $this->mbChapterParser->loadRecordings();
            $parsedChapters = $this->mbChapterParser->parseRecordings($mbXml);
        }

        $duration = $this->metaHandler->estimateDuration($this->filesToProcess);
        if (!($duration instanceof TimeUnit)) {
            throw new Exception(sprintf("Could not detect duration for file %s", $this->filesToProcess));
        }

        $this->buildChapters($parsedChapters, $duration);
        if (!$this->input->getOption(static::OPTION_ADJUST_BY_SILENCE)) {
            $this->normalizeChapters($duration);
        }

        if (!$this->input->getOption(static::OPTION_NO_CHAPTER_IMPORT)) {
            $this->metaHandler->importChapters($this->outputFile, $this->chapters);
        }

        $chaptersTxtFile = $this->audioFileToChaptersFile($this->outputFile);
        $this->metaHandler->exportChapters($this->outputFile, $chaptersTxtFile);

    }

    private function initParsers()
    {
        $mbId = $this->input->getOption(static::OPTION_MUSICBRAINZ_ID);
        if ($mbId) {
            $this->mbChapterParser = new MusicBrainzChapterParser($mbId);
            $this->mbChapterParser->setCacheAdapter($this->cacheAdapter);
        }
    }

    private function loadFileToProcess()
    {
        $this->filesToProcess = new SplFileInfo($this->input->getArgument(static::ARGUMENT_INPUT));
        if (!$this->filesToProcess->isFile()) {
            $this->notice("Input file is not a valid file, currently directories are not supported");
            return;
        }
    }

    /**
     * @param string $epubFile
     * @param Silence[] $silences
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function handleEpub(string $epubFile = null, array $silences = [])
    {
        $originalTag = $this->metaHandler->readTag($this->filesToProcess);

        $backupFile = new SplFileInfo($this->filesToProcess->getPath() . "/" . $this->filesToProcess->getBasename($this->filesToProcess->getExtension()) . "chapters-backup.txt");
        if (!$backupFile->isFile()) {
            file_put_contents($backupFile, $this->mp4v2->chaptersToMp4v2Format($originalTag->chapters));
        }


        $tag = new Tag();

        $tagChanger = new TagImproverComposite();
        $tagChanger->mp4v2 = $this->mp4v2;
        $tagChanger->add(Tag\ChaptersTxt::fromFile($this->filesToProcess, $backupFile->getBasename()));
        $tagChanger->add(Tag\ContentMetadataJson::fromFile($this->filesToProcess));
        $tagChanger->add(new Tag\MergeSubChapters($this->chapterHandler));
        $tagChanger->add(
            new Tag\GuessChaptersBySilence($this->chapterMarker,
                $silences,
                $this->metaHandler->inspectExactDuration($this->filesToProcess)
            )
        );
        $tagChanger->add(ChaptersFromEpub::fromFile(
            $this->chapterHandler,
            $this->filesToProcess,
            $this->metaHandler->estimateDuration($this->filesToProcess),
            array_map(
                function ($value) {
                    $trimmedValue = trim($value);
                    if (!is_numeric($trimmedValue)) {
                        return PHP_INT_MAX;
                    }
                    return (int)$trimmedValue;
                },
                explode(",", $this->input->getOption(static::OPTION_EPUB_IGNORE_CHAPTERS))
            ),
            $epubFile
        ));
        $tagChanger->add(new Tag\RemoveDuplicateFollowUpChapters($this->chapterHandler));
        // chapter adjustments have to be the last chapter handling option
        $tagChanger->add(new AdjustTooLongChapters(
            $this->metaHandler,
            $this->chapterHandler,
            $this->filesToProcess,
            $this->input->getOption(static::OPTION_MAX_CHAPTER_LENGTH),
            (int)$this->input->getOption(static::OPTION_SILENCE_MIN_LENGTH)
        ));

        $tagChanger->improve($tag);
        $this->metaHandler->writeTag($this->filesToProcess, $tag);
    }

    private function loadOutputFile()
    {
        $this->outputFile = $this->input->getOption(static::OPTION_OUTPUT_FILE);
        if ($this->outputFile === "") {
            $this->outputFile = $this->filesToProcess->getPath() . DIRECTORY_SEPARATOR . $this->filesToProcess->getBasename("." . $this->filesToProcess->getExtension()) . ".chapters.txt";
            if (file_exists($this->outputFile) && !$this->input->getOption(static::OPTION_FORCE)) {
                $this->warning("output file already exists, add --force option to overwrite");
                $this->outputFile = "";
            }
        }
    }

    /**
     * @param array $mbChapters
     * @param TimeUnit $duration
     * @throws Exception
     */
    private function buildChapters(array $mbChapters, TimeUnit $duration)
    {
        $this->notice(sprintf("found %s musicbrainz chapters and %s silences within length %s", count($mbChapters), count($this->silences), $duration->format()));
        $this->chapters = $this->chapterMarker->guessChaptersBySilences($mbChapters, $this->silences, $duration);
    }

    /**
     * @param TimeUnit $duration
     * @throws Exception
     */
    protected function normalizeChapters(TimeUnit $duration)
    {

        $options = [
            static::OPTION_FIRST_CHAPTER_OFFSET => (int)$this->input->getOption(static::OPTION_FIRST_CHAPTER_OFFSET),
            static::OPTION_LAST_CHAPTER_OFFSET => (int)$this->input->getOption(static::OPTION_LAST_CHAPTER_OFFSET),
            static::OPTION_MERGE_SIMILAR => $this->input->getOption(static::OPTION_MERGE_SIMILAR),
            static::OPTION_NO_CHAPTER_NUMBERING => $this->input->getOption(static::OPTION_NO_CHAPTER_NUMBERING),
            static::OPTION_CHAPTER_PATTERN => $this->input->getOption(static::OPTION_CHAPTER_PATTERN),
            static::OPTION_CHAPTER_REMOVE_CHARS => $this->input->getOption(static::OPTION_CHAPTER_REMOVE_CHARS),
        ];


        $this->chapters = $this->chapterMarker->normalizeChapters($this->chapters, $options);;

        $specialOffsetChapterNumbers = $this->parseSpecialOffsetChaptersOption();

        if (count($specialOffsetChapterNumbers)) {

            /**
             * @var Chapter[] $numberedChapters
             */
            $numberedChapters = array_values($this->chapters);

            $misplacedTolerance = (int)$this->input->getOption(static::OPTION_FIND_MISPLACED_TOLERANCE);
            foreach ($numberedChapters as $index => $chapter) {


                if (in_array($index, $specialOffsetChapterNumbers)) {
                    $start = isset($numberedChapters[$index - 1]) ? $numberedChapters[$index - 1]->getEnd()->milliseconds() : 0;
                    $end = isset($numberedChapters[$index + 1]) ? $numberedChapters[$index + 1]->getStart()->milliseconds() : $duration->milliseconds();

                    $maxOffsetMilliseconds = (int)$this->input->getOption(static::OPTION_FIND_MISPLACED_OFFSET) * 1000;
                    if ($maxOffsetMilliseconds > 0) {
                        $start = max($start, $chapter->getStart()->milliseconds() - $maxOffsetMilliseconds);
                        $end = min($end, $chapter->getStart()->milliseconds() + $maxOffsetMilliseconds);
                    }


                    $specialOffsetStart = clone $chapter->getStart();
                    $specialOffsetStart->add($misplacedTolerance);
                    $specialOffsetChapter = new Chapter($specialOffsetStart, clone $chapter->getLength(), "=> special off. -4s: " . $chapter->getName() . " - pos: " . $specialOffsetStart->format());
                    $this->chapters[$specialOffsetChapter->getStart()->milliseconds()] = $specialOffsetChapter;

                    $silenceIndex = 1;
                    foreach ($this->silences as $silence) {
                        if ($silence->isChapterStart()) {
                            continue;
                        }
                        if ($silence->getStart()->milliseconds() < $start || $silence->getStart()->milliseconds() > $end) {
                            continue;
                        }

                        $silenceClone = clone $silence;

                        $silenceChapterPrefix = $silenceClone->getStart()->milliseconds() < $chapter->getStart()->milliseconds() ? "=> silence " . $silenceIndex . " before: " : "=> silence " . $silenceIndex . " after: ";


                        /**
                         * @var TimeUnit $potentialChapterStart
                         */
                        $potentialChapterStart = clone $silenceClone->getStart();
                        $halfLen = (int)round($silenceClone->getLength()->milliseconds() / 2);
                        $potentialChapterStart->add($halfLen);
                        $potentialChapter = new Chapter($potentialChapterStart, clone $silenceClone->getLength(), $silenceChapterPrefix . $chapter->getName() . " - pos: " . $silenceClone->getStart()->format() . ", len: " . $silenceClone->getLength()->format());
                        $chapterKey = (int)round($potentialChapter->getStart()->milliseconds(), 0);
                        $this->chapters[$chapterKey] = $potentialChapter;


                        $specialSilenceOffsetChapterStart = clone $silence->getStart();
                        $specialSilenceOffsetChapterStart->add($misplacedTolerance);
                        $specialSilenceOffsetChapter = new Chapter($specialSilenceOffsetChapterStart, clone $silence->getLength(), $potentialChapter->getName() . ' - tolerance');
                        $offsetChapterKey = (int)round($specialSilenceOffsetChapter->getStart()->milliseconds(), 0);
                        $this->chapters[$offsetChapterKey] = $specialSilenceOffsetChapter;

                        $silenceIndex++;
                    }
                }

                $chapter->setName($chapter->getName() . ' - index: ' . $index);
            }
            ksort($this->chapters);
        }


    }

    private function parseSpecialOffsetChaptersOption()
    {
        $tmp = explode(',', $this->input->getOption(static::OPTION_FIND_MISPLACED_CHAPTERS));
        $specialOffsetChapters = [];
        foreach ($tmp as $key => $value) {
            $chapterNumber = trim($value);
            if (is_numeric($chapterNumber)) {
                $specialOffsetChapters[] = (int)$chapterNumber;
            }
        }
        return $specialOffsetChapters;
    }


}
