<?php

declare(strict_types=1);

namespace ethaniccc\Oomph;

use ethaniccc\Oomph\event\OomphViolationEvent;
use ethaniccc\Oomph\session\OomphSession;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ScriptMessagePacket;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;

class Oomph extends PluginBase implements Listener {

    private const VALID_EVENTS = [
        "oomph:authentication",
        "oomph:latency_report",
        "oomph:flagged",
    ];

    private static Oomph $instance;

    /** @var string[] */
    public array $xuidList = [];

    /** @var OomphSession[] */
    private array $alerted = [];

    public static function getInstance(): Oomph {
        return self::$instance;
    }

    public function onEnable(): void {
        self::$instance = $this;

        if ($this->getConfig()->get("Version", "n/a") !== "1.0.0") {
            @unlink($this->getDataFolder() . "config.yml");
            $this->reloadConfig();
        }

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->alerted = [];
            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                if (!$player->hasPermission("Oomph.Alerts")) {
                    continue;
                }

                $session = OomphSession::get($player);
                if ($session === null) {
                    continue;
                }

                if (microtime(true) - $session->lastAlert < $session->alertDelay) {
                    continue;
                }

                $this->alerted[] = $session;
            }
        }), 1);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            return false;
        }

        switch ($command->getName()) {
            case "oalerts":
            case "odelay":
                if (!$sender->hasPermission("Oomph.Alerts")) {
                    $sender->sendMessage(TextFormat::RED . "Insufficient permissions");
                    return true;
                }

                $session = OomphSession::get($sender);
                if ($session === null) {
                    $sender->sendMessage(TextFormat::RED . "Unexpected null session.");
                    return true;
                }

                if ($command->getName() === "oalerts") {
                    $session->alertsEnabled = !$session->alertsEnabled;
                    if ($session->alertsEnabled) {
                        $sender->sendMessage(TextFormat::GREEN . "Alerts enabled.");
                    } else {
                        $sender->sendMessage(TextFormat::RED . "Alerts disabled.");
                    }
                } else {
                    $delay = max((float) ($args[0] ?? 3), 0.05);
                    $session->alertDelay = $delay;
                    $sender->sendMessage(TextFormat::GREEN . "Alert delay set to $delay seconds");
                }

                return true;
        }

        return false;
    }

    /** @priority HIGHEST */
    public function onPreLogin(PlayerPreLoginEvent $event): void {
        $ref = (new \ReflectionClass($event))->getProperty("playerInfo");
        /** @var PlayerInfo $playerInfo */
        $playerInfo = $ref->getValue($event);
        $extraData = $playerInfo->getExtraData();
        $extraData["Xuid"] =  $this->xuidList["{$event->getIp()}:{$event->getPort()}"];
        $extraData["Username"] = $playerInfo->getUsername();
        $playerInfo = new XboxLivePlayerInfo(
            $this->xuidList["{$event->getIp()}:{$event->getPort()}"],
            $playerInfo->getUsername(),
            $playerInfo->getUuid(),
            $playerInfo->getSkin(),
            $playerInfo->getLocale(),
            $extraData,
        );
        $ref->setValue($event, $playerInfo);
    }

    public function onLogin(PlayerLoginEvent $event): void {
        $player = $event->getPlayer();
        (new \ReflectionClass($player))->getProperty("xuid")->setValue($player, $this->xuidList["{$player->getNetworkSession()->getIp()}:{$player->getNetworkSession()->getPort()}"]);
        unset($this->xuidList["{$player->getNetworkSession()->getIp()}:{$player->getNetworkSession()->getPort()}"]);

        OomphSession::register($player);
    }

    public function onQuit(PlayerQuitEvent $event): void {
        OomphSession::unregister($event->getPlayer());
    }

    /** @priority HIGHEST */
    public function onClientPacket(DataPacketReceiveEvent $event): void {
        $player = $event->getOrigin()->getPlayer();
        $packet = $event->getPacket();

        if (!$packet instanceof ScriptMessagePacket) {
            return;
        }

        $eventType = $packet->getMessageId();

        if (!in_array($eventType, self::VALID_EVENTS)) {
            return;
        }

        $data = json_decode($packet->getValue(), true);
        if ($data === null) {
            return;
        }

        $event->cancel();
        switch ($eventType) {
            case "oomph:authentication":
                $this->xuidList[$event->getOrigin()->getIp() . ":" . $event->getOrigin()->getPort()] = $data["xuid"];
                break;
            case "oomph:latency_report":
                if ($player === null) {
                    return;
                }

                $player->getNetworkSession()->updatePing((int) $data["raknet"]);
                break;
            case "oomph:flagged":
                if ($player === null) {
                    return;
                }

                $message = $this->getConfig()->get("message", "{prefix} §d{player} §7flagged §4{check_main} §7(§c{check_sub}§7) §7[§5x{violations}§7]");
                $message = str_replace(
                    ["{prefix}", "{player}", "{check_main}", "{check_sub}", "{violations}"],
                    [$this->getConfig()->get("Prefix", "§l§7[§eoomph§7]"), $data["player"], $data["check_main"], $data["check_sub"], $data["violations"]],
                    $message
                );

                $ev = new OomphViolationEvent($player, $data["check_main"], $data["check_sub"], round($data["violations"], 2));
                $ev->call();

                if (!$ev->isCancelled()) {
                    foreach ($this->alerted as $session) {
                        $session->getPlayer()->sendMessage($message);
                        $session->lastAlert = microtime(true);
                    }
                }

                break;
        }
    }

}
