<?php

namespace App\Controller;

use App\Repository\AnalysisRepository;
use Psr\Log\LoggerInterface;
use App\Repository\RunnerRepository;
use App\Service\GitRepositoryManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

#[Route('/runner')]
class RunnerController extends AbstractController {
    #[Route('/run/{analysis_hash}/{runner_id}', name: 'runner.run', methods: ['POST'])]
    public function run(EntityManagerInterface $entityManager, AnalysisRepository $analysisRepository, GitRepositoryManager $gitManager, RunnerRepository $runnerRepository, LoggerInterface $logger, string $analysis_hash, int $runner_id): JsonResponse {
        $logger->info("runner {$runner_id} started.");
        
        $runner = $runnerRepository->find($runner_id);
        $analysis = $analysisRepository->findOneBy(['hash' => $analysis_hash]);

        if($runner->getAnalysis()->getId()!==$analysis->getId()){
            $logger->error("ERROR 3306 : Match between analysis and runner failed.");
            return new JsonResponse(['success'=> false, 'reason' => 'Forbidden']);
        };
        $cmd = $runner->getContextModule()->getCommand();
        
        $cmdArray = explode(' ', $cmd);
        
        $process = new Process($cmdArray);
        try {
            $process->setWorkingDirectory($gitManager->getPath($analysis));
            $process->setTimeout(60);
            $process->setIdleTimeout(60);
            
            $runner->setStartedAt(new \DateTimeImmutable());
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $logger->error($e->getMessage());
            return new JsonResponse(['success'=> false, 'reason' => "Process failed {$e->getCode()}"]);
        } catch(ProcessTimedOutException $e){
            $logger->error($e->getMessage());
            return new JsonResponse(['success'=> false, 'reason' => 'Time out']);
        }

        $runner->setFinishedAt(new \DateTimeImmutable());
        $runner->setOutput($process->getOutput());

        $entityManager->persist($runner);
        $entityManager->flush();

        $gitManager->delete($analysis);


        $logger->info("runner {$runner_id} finished.");
        
        // return new JsonResponse(['success' => true, 'reason' => 'Successfully Finished']);
        return new JsonResponse(['success' => true, 'reason' => 'Successfully Finished', 'output' => $runner->getOutput()]);
    }
}