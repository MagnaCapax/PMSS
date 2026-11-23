<?php
namespace PMSS\Tests;

/**
 * Intent: scripts/lib/tests/development/AgenticCommitMessagePromptTest.php -- guards that the agent commit prompts
 * steer multi-line commit messages away from a literal backslash-n inside one git commit -m argument.
 */
class AgenticCommitMessagePromptTest extends TestCase
{
    public function testCommitPromptsTellAgentsHowToWriteMultiLineMessages(): void
    {
        foreach (array('development/prompts/autocommit.txt', 'development/prompts/ci.txt') as $prompt) {
            $text = $this->pmssReadRepoFile($prompt);
            $this->assertStringContainsAllStrings(array('git commit -F <file>', 'backslash and n', '\\n'), $text, $prompt.': ');
        }
    }

    public function testAutocommitPromptKeepsTheSingleLineTemplateAndAddsTheRuleInsideTheCommitBlock(): void
    {
        $text = $this->pmssReadRepoFile('development/prompts/autocommit.txt');
        $this->assertOrderedStrings(array('COMMIT:', 'git commit -m "##COMMIT_PREFIX##', 'git commit -F <file>',
            'NEVER type \\n inside one -m argument', 'COMMIT MESSAGE RULE'), $text);
    }
}
