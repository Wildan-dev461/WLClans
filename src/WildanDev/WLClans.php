<?php

namespace WildanDev;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use onebone\economyapi\EconomyAPI;

class WLClans extends PluginBase {

    private $clans = [];
    private $data;
    private $pendingInvitations = [];

    public function onEnable(): void {
        $this->getLogger()->info("ClansPlugin enabled.");

        $this->saveDefaultConfig();

        $this->data = new Config($this->getDataFolder() . "clans.json", Config::JSON);

        // Load existing clan data
        if ($this->data->exists("clans")) {
            $this->clans = $this->data->get("clans");
        }

        // Register EconomyAPI as a dependency
        if ($this->getServer()->getPluginManager()->getPlugin("EconomyAPI") === null) {
            $this->getLogger()->error("EconomyAPI is not installed.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }
    }

    public function onDisable(): void {
        $this->getLogger()->info("ClansPlugin disabled.");

        // Save clan data
        $this->data->set("clans", $this->clans);
        $this->data->save();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game.");
            return true;
        }

        if ($command->getName() === "clan") {
            $playerClan = $this->getPlayerClan($sender);

            if ($playerClan === "N/A") {
                // Handle create/list/leave/accept/reject commands for players with no clan
                if (empty($args) || !isset($args[0])) {
                    $sender->sendMessage(TF::YELLOW . "Usage: /clan create <clanName>");
                    $sender->sendMessage(TF::YELLOW . "Usage: /clan list");
                } else {
                    $subCommand = strtolower($args[0]);
                    switch ($subCommand) {
                        case "create":
                            if (isset($args[1])) {
                                $clanName = $args[1];
                                $this->createClan($clanName, $sender);
                            } else {
                                $sender->sendMessage(TF::RED . "Usage: /clan create <clanName>");
                            }
                            break;
                        case "list":
                            // Handle listing clans here
                            $this->sendListClansForm($sender);
                            break;
                        case "accept":
                            $this->acceptInvitation($sender);
                            break;
                        case "reject":
                            $this->rejectInvitation($sender);
                            break;
                        default:
                            $sender->sendMessage(TF::RED . "Unknown sub-command. Use '/clan create', '/clan list', '/clan accept', or '/clan reject'.");
                            break;
                    }
                }
            } else {
                // Handle leave/disband/kick/invite commands for clan members and leaders
                if (empty($args) || !isset($args[0])) {
                    $sender->sendMessage(TF::YELLOW . "Usage: /clan leave");
                    $sender->sendMessage (TF::YELLOW . "Usage: /clan listmember");
                    if ($this->isPlayerLeader($sender)) {
                        $sender->sendMessage(TF::YELLOW . "Usage: /clan disband");
                        $sender->sendMessage(TF::YELLOW . "Usage: /clan kick <playerName>");
                        $sender->sendMessage(TF::YELLOW . "Usage: /clan invite <playerName>");
                    }
                } else {
                    $subCommand = strtolower($args[0]);
                    switch ($subCommand) {
                        case "leave":
                            $this->leaveClan($playerClan, $sender);
                            break;
                        case "disband":
                            if ($this->isPlayerLeader($sender)) {
                                $this->disbandClan($playerClan);
                            } else {
                                $sender->sendMessage(TF::RED . "You must be the clan leader to disband the clan.");
                            }
                            break;
                        case "listmember":
                            // Handle listing clan members here
                            if ($this->isPlayerInClan($sender)) {
                                $clanName = $this->getPlayerClan($sender);
                                $clanData = $this->getClanData($clanName);
                                $members = $clanData["members"];
                                $memberList = implode(", ", $members);
                                $sender->sendMessage(TF::AQUA . "Clan Members in '{$clanName}':");
                                $sender->sendMessage(TF::YELLOW . $memberList);
                            }
                            break;
                        case "kick":
                            if ($this->isPlayerLeader($sender)) {
                                if (isset($args[1])) {
                                    $playerName = $args[1];
                                    $this->kickPlayer($playerName, $playerClan);
                                } else {
                                    $sender->sendMessage(TF::RED . "Usage: /clan kick <playerName>");
                                }
                            } else {
                                $sender->sendMessage(TF::RED . "You must be the clan leader to kick a player.");
                            }
                            break;
                        case "invite":
                            if ($this->isPlayerLeader($sender)) {
                                if (isset($args[1])) {
                                    $playerName = $args[1];
                                    $this->invitePlayer($playerName, $sender);
                                } else {
                                    $sender->sendMessage(TF::RED . "Usage: /clan invite <playerName>");
                                }
                            } else {
                                $sender->sendMessage(TF::RED . "Only the clan leader can send invitations.");
                            }
                            break;
                        default:
                            $sender->sendMessage(TF::RED . "Unknown sub-command. Use '/clan leave', '/clan disband', '/clan kick', '/clan invite', or '/clan listmember'.");
                            break;
                    }
                }
            }
        }

        return true;
    }

    public function invitePlayer(string $playerName, Player $leader): void {
        $clanName = $this->getPlayerClan($leader);

        if (!$this->isClanExists($clanName)) {
            $leader->sendMessage(TF::RED . "You are not in a clan.");
            return;
        }

        if ($this->isPlayerLeader($leader)) {
            $player = $this->getServer()->getPlayerExact($playerName);

            if ($player !== null) {
                $this->pendingInvitations[$playerName] = $clanName;
                $player->sendMessage(TF::YELLOW . "You have been invited to join clan '" . $clanName . "'.");
                $player->sendMessage(TF::YELLOW . "Type '/clan accept/reject " . $clanName . "' to accept/reject the invitation.");
                $leader->sendMessage(TF::GREEN . "Invitation sent to " . $playerName . ".");
            } else {
                $leader->sendMessage(TF::RED . "Player '" . $playerName . "' is not online.");
            }
        } else {
            $leader->sendMessage(TF::RED . "Only the clan leader can send invitations.");
        }
    }

    public function createClan(string $clanName, Player $leader): void {
        $this->clans[$clanName] = [
            "leader" => $leader->getName(),
            "members" => [$leader->getName()]
        ];
        $this->data->set("clans", $this->clans);
        $this->data->save();

        $leader->sendMessage(TF::GREEN . "Clan created successfully. You are now the leader of '" . $clanName . "'.");
    }

    public function leaveClan(string $clanName, Player $player): void {
        if ($this->isPlayerLeader($player)) {
            $player->sendMessage(TF::RED . "The clan leader cannot leave the clan. You can disband it instead.");
            return;
        }

        if ($this->isClanExists($clanName)) {
            $members = &$this->clans[$clanName]["members"];
            $key = array_search($player->getName(), $members);
            if ($key !== false) {
                unset($members[$key]);
                $player->sendMessage(TF::GREEN . "You left clan '" . $clanName . "'.");
            }
        }
    }

    public function disbandClan(string $clanName): void {
        if ($this->isClanExists($clanName)) {
            $leaderName = $this->clans[$clanName]["leader"];
            unset($this->clans[$clanName]);
            $this->data->set("clans", $this->clans);
            $this->data->save();

            $leader = $this->getServer()->getPlayerExact($leaderName);
            if ($leader !== null) {
                $leader->sendMessage(TF::RED . "Your clan '" . $clanName . "' has been disbanded.");
            }
        }
    }

    public function kickPlayer(string $playerName, string $clanName): void {
        if ($this->isClanExists($clanName)) {
            $leaderName = $this->clans[$clanName]["leader"];
            if ($leaderName === $playerName) {
                $leader = $this->getServer()->getPlayerExact($leaderName);
                if ($leader !== null) {
                    $leader->sendMessage(TF::RED . "You cannot kick yourself from the clan. You can disband it instead.");
                }
                return;
            }

            $members = &$this->clans[$clanName]["members"];
            $key = array_search($playerName, $members);
            if ($key !== false) {
                unset($members[$key]);
                $leader = $this->getServer()->getPlayerExact($leaderName);
                if ($leader !== null) {
                    $leader->sendMessage(TF::GREEN . $playerName . " has been kicked from the clan.");
                }
            }
        }
    }

    public function isPlayerLeader(Player $player): bool {
        $clanName = $this->getPlayerClan($player);

        if ($this->isClanExists($clanName)) {
            return $this->clans[$clanName]["leader"] === $player->getName();
        }

        return false;
    }

    public function sendListClansForm(Player $player): void {
        $clanList = [];
        foreach ($this->clans as $clanName => $clanData) {
            $clanList[] = $clanName . " (Leader: " . $clanData["leader"] . ")";
        }

        $player->sendMessage(TF::AQUA . "List of Clans:");
        if (!empty($clanList)) {
            foreach ($clanList as $clan) {
                $player->sendMessage(TF::YELLOW . "- " . $clan);
            }
        } else {
            $player->sendMessage(TF::YELLOW . "No clans found.");
        }
    }

    public function getPlayerClan(Player $player): string {
        $playerName = $player->getName();

        foreach ($this->clans as $clanName => $clanData) {
            if (isset($clanData["members"]) && in_array($playerName, $clanData["members"])) {
                return $clanName;
            }
        }

        return "N/A";
    }

    public function getClanData(string $clanName): array {
        return $this->clans[$clanName];
    }

    public function isClanExists(string $clanName): bool {
        return isset($this->clans[$clanName]);
    }

    public function joinClan(Player $player, string $clanName): void {
        if (!$this->isClanExists($clanName)) {
            $player->sendMessage(TF::RED . "The clan you are trying to join does not exist.");
            return;
        }

        $playerName = $player->getName();

        if ($this->getPlayerClan($player) === "N/A") {
            $this->clans[$clanName]["members"][] = $playerName;
            $this->data->set("clans", $this->clans);
            $this->data->save();
            $player->sendMessage(TF::GREEN . "You have joined clan '" . $clanName . "'.");
        } else {
            $player->sendMessage(TF::RED . "You are already in a clan. Leave your current clan to join another.");
        }
    }

    public function acceptInvitation(Player $player): void {
        $playerName = $player->getName();

        if (isset($this->pendingInvitations[$playerName])) {
            $clanName = $this->pendingInvitations[$playerName];
            unset($this->pendingInvitations[$playerName]);
            $this->joinClan($player, $clanName);
        } else {
            $player->sendMessage(TF::RED . "You don't have any pending clan invitations.");
        }
    }

    public function sendPendingInvitationsList(Player $player): void {
        $playerName = $player->getName();

        if (isset($this->pendingInvitations[$playerName])) {
            $clanName = $this->pendingInvitations[$playerName];
            $player->sendMessage(TF::AQUA . "Pending Clan Invitation:");
            $player->sendMessage(TF::YELLOW . "You have a pending invitation to join clan '" . $clanName . "'.");
            $player->sendMessage(TF::YELLOW . "Type '/clan accept/reject " . $clanName . "' to accept/reject the invitation.");
        } else {
            $player->sendMessage(TF::YELLOW . "You don't have any pending clan invitations.");
        }
    }

    // Other methods...

    // New method to check if a player is in a clan
    public function isPlayerInClan(Player $player): bool {
        $clanName = $this->getPlayerClan($player);
        return $clanName !== "N/A";
    }

    // New method to reject clan invitations
    public function rejectInvitation(Player $player): void {
        $playerName = $player->getName();

        if (isset($this->pendingInvitations[$playerName])) {
            $clanName = $this->pendingInvitations[$playerName];
            unset($this->pendingInvitations[$playerName]);
            $player->sendMessage(TF::RED . "You have rejected the invitation to join clan '" . $clanName . "'.");
        } else {
            $player->sendMessage(TF::RED . "You don't have any pending clan invitations to reject.");
        }
    }
}
