<?php
namespace bbaamm\company;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use ifteam\SimpleArea\database\minefarm\MineFarmManager;
use ifteam\SimpleArea\database\area\AreaSection;
use ifteam\SimpleArea\database\area\AreaManager;
use ifteam\SimpleArea\database\area\AreaProvider;
use onebone\economyapi\EconomyAPI;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use ifteam\SimpleArea\database\minefarm\MineFarmLoader;
use pocketmine\event\player\PlayerChatEvent;

class Company extends PluginBase implements Listener
{

    public $data, $db;

    public $config, $c;

    public $prefix;

    private static $instance = null;

    public function onLoad()
    {
        if (self::$instance === null)
            self::$instance = $this;
    }

    public static function getInstance()
    {
        return static::$instance;
    }

    public function onEnable()
    {
        if (! file_exists($this->getDataFolder()))
            @mkdir($this->getDataFolder());
        $this->open();
        $this->prefix = $this->c["pluginPrefix"];
        $this->getServer()
            ->getPluginManager()
            ->registerEvents($this, $this);
    }

    public function getPoint($companyId)
    {
        if (isset($this->db["point"]["{$companyId}번"])) {
            return $this->db["point"]["{$companyId}번"];
        }
    }

    public function sortPoints(): array
    {
        if (count($this->db["point"]) > 0) {
            arsort($this->db["point"]);
            $points = [];
            foreach ($this->db["point"] as $companyId => $point) {
                $points["{$companyId}번"] = $point;
            }
            return $points;
        } else {
            return [
                false
            ];
        }
    }

    public function canExtendMaxMember($companyId)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            $point = $this->getPoint($companyId);
            if ($point < 10000) {
                return false;
            } else {
                return true;
            }
        } else {
            return false;
        }
    }

    public function extendMaxMember($companyId)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            if ($this->canExtendMaxMember($companyId)) {
                $this->db["company"]["{$companyId}번"]["maxMember"] += 5;
                $this->decreasePoint($companyId, 10000);
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function decreasePoint($companyId, $point)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            $this->db["point"]["{$companyId}번"] -= $point;
        } else {
            return false;
        }
    }

    public function increasePoint($companyId, $point)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            $this->db["point"]["{$companyId}번"] += $point;
        } else {
            return false;
        }
    }

    public function getPrejoinerList($companyId): array
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            return array_keys($this->db["company"]["{$companyId}번"]["queue"]);
        } else {
            return [];
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        $command = $command->getName();
        if ($command == "회사") {
            if (! isset($args[0]))
                $args[0] = 'x';
            switch ($args[0]) {
                case "설립":
                    if (! $sender instanceof Player) {
                        $sender->sendMessage($this->prefix . "인게임에서 실행하세요.");
                        return true;
                    }
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "회사 이름을 입력하세요.");
                        return true;
                    }
                    if (! isset($args[2])) {
                        $sender->sendMessage($this->prefix . "회사 섬으로 쓸 섬의 번호를 입력하세요.");
                        return true;
                    }
                    if (! is_numeric($args[2])) {
                        $sender->sendMessage($this->prefix . "섬 번호는 숫자로 입력하세요.");
                        return true;
                    }
                    $this->OpenCompany($sender, count($this->db["point"]), $args[1], $args[2]);
                    break;
                case "부회장임명":
                case "부회장":
                    if (! $sender instanceof Player) {
                        $sender->sendMessage($this->prefix . "인게임에서 실행하세요.");
                        return true;
                    }
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "부회장으로 임명할 플레이어의 닉네임을 써주세요.");
                        return true;
                    }
                    $this->SetSubownerCommand($sender, $args[1]);
                    break;
                case "부회장박탈":
                    if (! $sender instanceof Player) {
                        $sender->sendMessage($this->prefix . "인게임에서 실행하세요.");
                        return true;
                    }
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "/회사 부회장박탈 [닉네임]");
                        return true;
                    }
                    $this->UnsetSubownerCommand($sender, $args[1]);
                    break;
                case "순위":
                case "랭킹":
                case "랭크":
                    if (isset($args[1]))
                        if (! is_numeric($args[1])) {
                            $sender->sendMessage($this->prefix . "페이지는 숫자로 입력하세요.");
                            return true;
                        }
                    $this->SeeCompanyRankingCommand($sender);
                    break;
                case "환전":
                    if (! $sender instanceof Player) {
                        $sender->sendMessage($this->prefix . "인게임에서 실행하세요.");
                        return true;
                    }
                    $this->ExchangePointCommand($sender);
                    break;
                case "확장":
                    if (! $sender instanceof Player) {
                        $sender->sendMessage($this->prefix . "인게임에서 실행하세요.");
                        return true;
                    }
                    $this->ExtendMaxMemberCommand($sender, $this->getCompanyId($sender));
                    break;
                case "정보":
                case "정보보기":
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "/회사 정보 [회사아이디] | 회사의 정보를 훔쳐(?)봅니다.");
                        $sender->sendMessage($this->prefix . "정보를 볼 회사의 아이디를 입력하세요.");
                        return true;
                    }
                    $this->InfoCommand($sender, $args[1]);
                    break;
                case "가입신청":
                case "갑신":
                case "신청":
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "/회사 가입신청 [회사아이디] | 해당 회사에 가입신청을 합니다.");
                        $sender->sendMessage($this->prefix . "가입신청을 넣을 회사의 아이디를 입력하세요.");
                        return true;
                    }
                    $this->PreJoinCommand($sender, $args[1]);
                    break;
                case "가입신청취소":
                case "취소":
                    $this->CancleQueueCommand($sender);
                    break;
                case "수락":
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "/회사 수락 [닉네임] | 가입신청을 수락합니다.");
                        return true;
                    }
                    $this->AcceptCommand($sender, $args[1]);
                    break;
                case "거절":
                case "거부":
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "/회사 거절 [닉네임]");
                        return true;
                    }
                    $this->RejectCommand($sender, $args[1]);
                    break;
                case "신청목록":
                case "가입신청목록":
                    $this->PreJoinListCommand($sender);
                    break;
                case "강퇴":
                case "추방":
                    if (! isset($args[1])) {
                        $sender->sendMessage($this->prefix . "/회사 강퇴 [닉네임] | [닉네임] 님을 강퇴합니다."); //
                        $sender->sendMessage($this->prefix . "강퇴할 플레이어의 닉네임도 함께 입력하세요.");
                        return true;
                    }
                    $this->ForceByeCommand($sender, $args[1]);
                    break;
                case "탈퇴":
                case "나가기":
                    $this->ByeCommand($sender);
                    break;
                case "폐쇄":
                case "닫기":
                    $this->DeleteCompany($sender);
                    break;
                case "채팅":
                    if ($sender instanceof Player) {
                        if ($this->hasCompany($sender)) {
                            if (! isset($this->chat[$sender->getLowerCaseName()])) {
                                $this->chat[$sender->getLowerCaseName()] = true;
                                $sender->sendMessage($this->prefix . "회사 채팅모드를 활성화했습니다.");
                            } else {
                                unset($this->chat[$sender->getLowerCaseName()]);
                                $sender->sendMessage($this->prefix . "회사 채팅모드를 비활성화 했습니다.");
                            }
                        } else {
                            $sender->sendMessage($this->prefix . "당신은 가입된 회사가 없습니다.");
                        }
                    }
                    break;
                case "양도":
                    if ($sender instanceof Player) {
                        if (isset($args[1])) {
                            if ($this->getServer()->getPlayer($args[1]) !== null) {
                                $this->ChangeOwner($sender, $this->getServer()
                                    ->getPlayer($args[1]));
                            } else {
                                $sender->sendMessage($this->prefix . "{$args[1]} 님은 접속중이 아닙니다.");
                            }
                        }
                    }
                    break;
                case "내정보":
                    if ($sender instanceof Player) {
                        if ($this->hasCompany($sender)) {
                            $id = $this->getCompanyId($sender);
                            $name = $this->getCompanyNameById($id);
                            $sender->sendMessage($this->prefix . "회사명: {$name}, 회사 아이디: {$id}");
                        } else {
                            $sender->sendMessage($this->prefix . "가입되어있는 회사가 없습니다.");
                        }
                    }
                    break;
                default:
                    $this->HelpCommand($sender);
                    break;
            }
        }
        return true;
    }

    public function HelpCommand(CommandSender $player)
    {
        $player->sendMessage($this->prefix . "/회사 내정보 | 회사명과 회사 아이디를 확인합니다.");
        $player->sendMessage($this->prefix . "/회사 설립 [회사이름] [회사섬번호] | 회사를 설립합니다!");
        $player->sendMessage($this->prefix . "/회사 부회장임명 [닉네임] | 부회장을 임명합니다.");
        $player->sendMessage($this->prefix . "/회사 순위 [페이지] | 회사들의 순위를 봅니다.");
        $player->sendMessage($this->prefix . "/회사 환전 | 회장 및 부회장의 돈을 포인트로 환전합니다. (10만 -> 1500포인트)");
        $player->sendMessage($this->prefix . "/회사 확장 | 회사 최대인원을 확장합니다.");
        $player->sendMessage($this->prefix . "/회사 정보 [회사아이디] | 회사의 정보를 훔쳐(?)봅니다.");
        $player->sendMessage($this->prefix . "/회사 가입신청 [회사아이디] | 해당 회사에 가입신청을 합니다.");
        $player->sendMessage($this->prefix . "/회사 채팅 | 회사 채팅모드. (한 번 더 입력시 비활성화)"); //
        $player->sendMessage($this->prefix . "/회사 가입신청취소 | 가입신청을 취소합니다.");
        $player->sendMessage($this->prefix . "/회사 수락 [닉네임] | 가입신청을 수락합니다.");
        $player->sendMessage($this->prefix . "/회사 거절 [닉네임] | 가입신청을 거절합니다."); //
        $player->sendMessage($this->prefix . "/회사 신청목록 | 가입신청을 목록을 봅니다.");
        $player->sendMessage($this->prefix . "/회사 강퇴 [닉네임] | [닉네임] 님을 강퇴합니다."); //
        $player->sendMessage($this->prefix . "/회사 탈퇴 | 회사를 탈퇴합니다."); //
        $player->sendMessage($this->prefix . "/회사 부회장박탈 [닉네임] | 부회장 자격을 박탈합니다."); //
        $player->sendMessage($this->prefix . "/회사 양도 [닉네임] | 회사의 회장을 바꿉니다."); //
        $player->sendMessage($this->prefix . "/회사 폐쇄 | 회사를 없앱니다."); //
        if (! $player instanceof Player) {
            $player->sendMessage($this->prefix . "/회사 포인트초기화 | 콘솔에서만 쓸 수 있습니다. (어드민용)");
        }
    }

    public function SeeCompanyRankingCommand(Player $player, $index = 1)
    {
        $array = $this->sortPoints();
        if (count($this->db["point"]) < 1) {
            $player->sendMessage($this->prefix . "현재 설립된 회사가 하나도 없습니다.");
            return true;
        }
        $maxpage = floor(count($array) / 5) + 1;
        if ($maxpage < $index)
            $index = $maxpage;
        $axis1 = $index * 5 - 4;
        $axis2 = $index * 5;
        $count = 0;
        $player->sendMessage("§l§b[§f회사 순위| {$index}페이지§b]");
        foreach ($array as $companyId => $point) {
            $count ++;
            $companyId = str_replace("번", "", $companyId);
            $companyName = $this->getCompanyNameById($companyId);
            if ($axis1 <= $count and $axis2 >= $count) {
                $player->sendMessage("§l§b[{$count}위§b] §r§f회사명: {$companyName}, 포인트: {$point}포인트, 회사아이디: {$companyId}");
            }
        }
    }

    public function ByeCommand(Player $player)
    {
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        if ($this->getOwnerById($companyId) == strtolower($player->getName())) {
            $player->sendMessage($this->prefix . "회장은 탈퇴할 수 없습니다!");
            return true;
        }
        $this->db["players"][strtolower($player->getName())] = [
            "companyId" => false,
            "companyName" => false,
            "queue" => false
        ];
        unset($this->db["company"]["{$companyId}번"]["members"][strtolower($player->getName())]);
        foreach ($this->getMembersById($companyId) as $name => $rate) {
            if ($this->getServer()->getPlayer($name) !== null) {
                $this->getServer()
                    ->getPlayer($name)
                    ->sendMessage($this->prefix . "{$player->getName()} 님이 회사에서 탈퇴했습니다.");
            }
        }
        $player->sendMessage($this->prefix . "회사에서 탈퇴했습니다.");
    }

    public function ForceByeCommand(Player $player, $counter)
    {
        $counter = strtolower($counter);
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        if ($this->getOwnerById($companyId) !== strtolower($player->getName())) {
            $player->sendMessage($this->prefix . "해당 명령어는 회장만 할 수 있습니다.");
            return true;
        }
        if (strtolower($player->getName()) == $counter) {
            $player->sendMessage($this->prefix . "자기 자신을 강퇴할 수 없습니다.");
            return true;
        }
        if ($this->getCompanyId($counter) !== $companyId) {
            $player->sendMessage($this->prefix . "해당 플레이어는 당신 회사 소속이 아닙니다.");
            return true;
        }
        $this->db["players"][strtolower($counter)] = [
            "companyId" => false,
            "companyName" => false,
            "queue" => false
        ];
        foreach ($this->getMembersById($companyId) as $name => $rate) {
            if ($this->getServer()->getPlayer($name) !== null) {
                $this->getServer()
                    ->getPlayer($name)
                    ->sendMessage($this->prefix . "{$counter} 님이 회사에서 강퇴됐습니다.");
                unset($this->db["company"]["{$companyId}번"]["members"][strtolower($counter)]);
            }
        }
        $player->sendMessage($this->prefix . "{$counter} 님을 강퇴했습니다.");
    }

    public function SetSubownerCommand(Player $player, $subowner)
    {
        $subowner = strtolower($subowner);
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        if ($this->getOwnerById($companyId) !== strtolower($player->getName())) {
            $player->sendMessage($this->prefix . "해당 명령어는 회장만 할 수 있습니다.");
            return true;
        }
        if ($this->getServer()->getPlayer($subowner) !== null) {
            $subowner = $this->getServer()
                ->getPlayer($subowner)
                ->getName();
            $subowner = strtolower($subowner);
        }
        if (! isset($this->db["players"][$subowner])) {
            $player->sendMessage($this->prefix . "해당 닉네임인 플레이어를 찾을 수 없습니다.");
            return true;
        }
        if ($this->db["players"][$subowner]["companyId"] != $companyId) {
            $player->sendMessage($this->prefix . "해당 플레이어는 당신의 회사원이 아닙니다!");
            return true;
        }
        $this->db["company"]["{$companyId}번"]["members"][$subowner] = "부회장";
        $player->sendMessage($this->prefix . "{$subowner}님을 부회장으로 임명했습니다!");
        foreach ($this->getMembersById($companyId) as $name => $rate) {
            if ($this->getServer()->getPlayer($name) !== null) {
                $this->getServer()
                    ->getPlayer($name)
                    ->sendMessage($this->prefix . "{$subowner}님을 부회장으로 임명했습니다!");
            }
        }
    }

    public function UnsetSubownerCommand(Player $player, $subowner)
    {
        $subowner = strtolower($subowner);
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        if ($this->getOwnerById($companyId) !== strtolower($player->getName())) {
            $player->sendMessage($this->prefix . "해당 명령어는 회장만 할 수 있습니다.");
            return true;
        }
        if ($this->getServer()->getPlayer($subowner) !== null) {
            $subowner = $this->getServer()
                ->getPlayer($subowner)
                ->getName();
            $subowner = strtolower($subowner);
        }
        if (! isset($this->db["players"][$subowner])) {
            $player->sendMessage($this->prefix . "해당 닉네임인 플레이어를 찾을 수 없습니다.");
            return true;
        }
        if ($this->db["players"][$subowner]["companyId"] != $companyId) {
            $player->sendMessage($this->prefix . "해당 플레이어는 당신의 회사원이 아닙니다!");
            return true;
        }
        if ($this->db["company"]["{$companyId}번"]["members"][$subowner] !== "부회장") {
            $player->sendMessage($this->prefix . "해당 플레이어는 부회장이 아닙니다.");
            return true;
        }

        $this->db["company"]["{$companyId}번"]["members"][$subowner] = "사원";
        $player->sendMessage($this->prefix . "{$subowner}님의 부회장자격을 박탈했습니다!");
        foreach ($this->getMembersById($companyId) as $name => $rate) {
            if ($this->getServer()->getPlayer($name) !== null) {
                $this->getServer()
                    ->getPlayer($name)
                    ->sendMessage($this->prefix . "{$subowner}님의 부회장자격을 박탈했습니다!");
            }
        }
    }

    public function CancleQueueCommand(Player $player)
    {
        if (! $this->isQueue($player)) {
            $player->sendMessage($this->prefix . "당신은 가입신청을 한 적이 없습니다.");
            return true;
        }
        $companyId = $this->queueCompanyId($player);
        unset($this->db["company"]["{$companyId}번"]["queue"][strtolower($player->getName())]);
        $this->db["players"][strtolower($player->getName())]["queue"] = false;
        $player->sendMessage($this->prefix . "가입신청을 취소했습니다.");
    }

    public function InfoCommand(Player $player, $companyId)
    {
        if (! isset($this->db["company"]["{$companyId}번"])) {
            $player->sendMessage($this->prefix . "해당 아이디인 회사를 찾을 수 없습니다.");
            return true;
        }
        $companyName = $this->getCompanyNameById($companyId);
        $point = $this->getPoint($companyId);
        $members = $this->getMembersById($companyId);
        $memberss = [];
        $b = [];
        foreach ($members as $name => $rate) {
            $memberss[] = $name;
            if ($rate == "부회장") {
                $b[] = $name;
            }
        }
        if (count($b) < 1) {
            $b = "없음";
        } else {
            $b = $b[0];
        }
        $nowmember = count($members);
        $members = implode(", ", $memberss);
        $owner = $this->getOwnerById($companyId);
        $companyRate = $this->getCompanyRate($companyId);
        $maxmember = $this->getMaxMemberById($companyId);
        $player->sendMessage("§l§6========================\n{$companyName} §r§7정보\n§r§f회장: {$owner}, 부회장: {$b}\n사원: {$members}({$nowmember}/{$maxmember})\n포인트: {$point}\n§l§6========================");
    }

    public function getCompanyRate($companyId)
    {
        $list = $this->sortPoints();
        $count = 0;
        foreach ($list as $id => $point) {
            $count ++;
            if ($id == $companyId . "번")
                return $count;
        }
    }

    public function PreJoinCommand(Player $player, $companyId)
    {
        if ($this->isQueue($player)) {
            $player->sendMessage($this->prefix . "당신은 이미 다른 회사에 가입신청을 했습니다.");
            return true;
        }
        if ($this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 이미 다른 회사소속입니다.");
            return true;
        }
        if (! isset($this->db["company"]["{$companyId}번"])) {
            $player->sendMessage($this->prefix . "해당 아이디로 회사를 찾을 수 없습니다.");
            return true;
        }
        $this->db["company"]["{$companyId}번"]["queue"][strtolower($player->getName())] = true;
        $this->db["players"][strtolower($player->getName())]["queue"] = (int) $companyId;
        $player->sendMessage($this->prefix . "가입신청을 넣었습니다. 회장, 부회장에게 문의하세요.");
        $companyMembers = $this->getMembersById($companyId);
        foreach ($companyMembers as $name => $rate) {
            if ($rate == "회장" or $rate == "부회장") {
                if ($this->getServer()->getPlayer($name) !== null) {
                    $this->getServer()
                        ->getPlayer($name)
                        ->sendMessage($this->prefix . "{$player->getName()} 님이 가입신청을 했습니다.");
                }
            }
        }
    }

    public function hasCompany($player)
    {
        if ($player instanceof Player)
            $player = $player->getName();
        $name = strtolower($player);
        if ($this->isRegistered($player)) {
            return is_numeric($this->db["players"][$name]["companyId"]) ? true : false;
        } else {
            return false;
        }
    }

    public function PreJoinListCommand(Player $player)
    {
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        $rate = $this->db["company"][$companyId . "번"]["members"][strtolower($player->getName())];
        if ($rate != "회장" and $rate != "부회장") {
            $player->sendMessage($this->prefix . "당신은 해당 명령어를 쓸 수 없습니다. 당신의 등급: {$rate}");
            return true;
        }
        $list = $this->getPrejoinerList($companyId);
        if (count($list) < 1) {
            $player->sendMessage($this->prefix . "가입신청이 오지 않았습니다.");
            return true;
        }
        $list = implode(", ", $list);
        $player->sendMessage($this->prefix . "§f가입신청 목록: {$list}");
        $player->sendMessage($this->prefix . "수락하시려면 /회사 수락 [닉네임], 거절하시려면 /회사 거절 [닉네임]");
    }

    public function RejectCommand(Player $player, $prejoiner)
    {
        $prejoiner = strtolower($prejoiner);
        if ($this->getServer()->getPlayer($prejoiner) !== null) {
            $prejoiner = $this->getServer()
                ->getPlayer($prejoiner)
                ->getName();
            $prejoiner = strtolower($prejoiner);
        }
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        $rate = $this->getRate($player);
        if ($rate != "회장" and $rate != "부회장") {
            $player->sendMessage($this->prefix . "당신은 해당 명령어를 쓸 수 없습니다.");
            return true;
        }
        if (! isset($this->db["company"]["{$companyId}번"]["queue"][$prejoiner])) {
            $player->sendMessage($this->prefix . "신청하지 않았거나 없는 플레이어입니다. 닉네임을 다시 확인하세요.");
            return true;
        }
        $companyName = $this->getCompanyNameById($companyId);
        $this->db["players"][$prejoiner]["queue"] = false;
        unset($this->db["company"]["{$companyId}번"]["queue"][$prejoiner]);
        $rate = $this->getRate($player);
        foreach ($this->getMembersById($companyId) as $name => $rate) {
            if ($this->getServer()->getPlayer($name) !== null) {
                $this->getServer()
                    ->getPlayer($name)
                    ->sendMessage($this->prefix . "{$rate} §a{$player->getName()} 님§7이 §a{$prejoiner}님§7의 가입신청을 수락했습니다.");
            }
        }
        if ($this->getServer()->getPlayer($prejoiner) !== null) {
            $this->getServer()
                ->getPlayer($prejoiner)
                ->sendMessage($this->prefix . "{$companyName} 회사가 거절했습니다.");
        }
    }

    public function DeleteCompany(Player $player)
    {
        if ($this->hasCompany($player) == false) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $rate = $this->getRate($player);
        if ($rate !== "회장") {
            $player->sendMessage($this->prefix . "회장만 사용 가능한 명령어 입니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        $companyName = $this->getCompanyNameById($companyId);
        if (count($this->getMembersById($companyId)) > 1) {
            $player->sendMessage($this->prefix . "회사를 없애려면 자신을 제외한 모든 인원을 강퇴해야합니다.");
            return true;
        }
        $this->db["players"][$player->getLowerCaseName()]["queue"] = false;
        $this->db["players"][$player->getLowerCaseName()]["companyId"] = false;
        $this->db["players"][$player->getLowerCaseName()]["companyName"] = false;
        unset($this->db["company"]["{$companyId}번"]);
        $player->sendMessage($this->prefix . "{$companyId}번 회사를 폐쇄했습니다.");
        $this->getServer()->broadcastMessage($this->prefix . "{$companyName} §r§7회사가 폐쇄됐습니다.");
    }

    public function AcceptCommand(Player $player, $prejoiner)
    {
        $prejoiner = strtolower($prejoiner);
        if ($this->getServer()->getPlayer($prejoiner) !== null) {
            $prejoiner = $this->getServer()
                ->getPlayer($prejoiner)
                ->getName();
            $prejoiner = strtolower($prejoiner);
        }
        if ($this->hasCompany($player) == false) {
            $player->sendMessage($this->prefix . "당신은 어느회사 소속도 아닙니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        $rate = $this->getRate($player);
        if ($rate != "회장" and $rate != "부회장") {
            $player->sendMessage($this->prefix . "당신은 해당 명령어를 쓸 수 없습니다.");
            return true;
        }
        if (! isset($this->db["company"]["{$companyId}번"]["queue"][$prejoiner])) {
            $player->sendMessage($this->prefix . "신청하지 않았거나 없는 플레이어입니다. 닉네임을 다시 확인하세요.");
            return true;
        }
        if ($this->getMaxMemberById($companyId) == count($this->db["company"]["{$companyId}번"]["members"])) {
            $player->sendMessage($this->prefix . "회사 최대인원이 꽉찼습니다!");
            return true;
        }
        $companyName = $this->getCompanyNameById($companyId);
        $this->db["players"][$prejoiner]["queue"] = false;
        $this->db["players"][$prejoiner]["companyId"] = $companyId;
        $this->db["players"][$prejoiner]["companyName"] = $this->getCompanyNameById($companyId);
        unset($this->db["company"]["{$companyId}번"]["queue"][$prejoiner]);
        $this->db["company"]["{$companyId}번"]["members"][$prejoiner] = "사원";
        $rate = $this->getRate($player);
        foreach ($this->getMembersById($companyId) as $name => $rate) {
            if ($this->getServer()->getPlayer($name) !== null) {
                $this->getServer()
                    ->getPlayer($name)
                    ->sendMessage($this->prefix . "{$rate} §a{$player->getName()} 님§7이 §a{$prejoiner}님§7의 가입신청을 수락했습니다.");
            }
        }
        if ($this->getServer()->getPlayer($prejoiner) !== null) {
            $this->getServer()
                ->getPlayer($prejoiner)
                ->sendMessage("§l§b================\n§f축하합니다! {$companyName}§o§l§f회사에\n§f가입되셨습니다!\n§b================");
        }
    }

    public function ExchangePointCommand(Player $player)
    {
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 회사가 없습니다.");
            return true;
        }
        if ($this->getRate($player) !== "회장" and $this->getRate($player) !== "부회장") {
            $player->sendMessage($this->prefix . "해당 명령어는 §a회장 및 부회장§7만 사용 가능합니다.");
            return true;
        }
        $companyId = $this->getCompanyId($player);
        $money = EconomyAPI::getInstance()->myMoney($player);
        if ($money < 100000) {
            $player->sendMessage($this->prefix . "환전하려면 100,000원이 필요합니다.");
            return true;
        }
        EconomyAPI::getInstance()->reduceMoney($player, 100000);
        $this->increasePoint($companyId, 1500);
        $point = $this->getPoint($companyId);
        $player->sendMessage($this->prefix . "환전을 완료했습니다. 현재 회사포인트: {$point}");
    }

    public function ExtendMaxMemberCommand(Player $player, $companyId)
    {
        if (! $this->hasCompany($player)) {
            $player->sendMessage($this->prefix . "당신은 회사가 없습니다.");
            return true;
        }
        if ($this->getRate($player) !== "회장" and $this->getRate($player) !== "부회장") {
            $player->sendMessage($this->prefix . "해당 명령어는 §a회장 및 부회장§7만 사용 가능합니다.");
            return true;
        }
        if (! $this->canExtendMaxMember($companyId)) {
            $player->sendMessage($this->prefix . "포인트가 부족합니다! (비용: §a10,000 포인트§7)");
            return true;
        }
        $this->extendMaxMember($companyId);
        $player->sendMessage($this->prefix . "최대 인원을 확장했습니다! 현재 최대인원: §a{$this->getMaxMemberById($companyId)}");
    }

    public function OpenCompany(Player $owner, int $companyId, string $companyName, int $companyIsland): bool
    {
        $area = AreaProvider::getInstance()->getAreaToId($owner->level, $companyIsland);
        if ($this->isQueue($owner)) {
            $owner->sendMessage($this->prefix . "당신은 이미 다른 회사에 가입신청을 했습니다.");
            return true;
        }
        if ($owner->level->getFolderName() !== "island") {
            $owner->sendMessage($this->prefix . "자신의 섬으로 가서 실행해주세요.");
            return true;
        }
        if (! $area instanceof AreaSection) {
            $owner->sendMessage($this->prefix . "회사 섬으로 사용할 수 없는 섬번호 입니다.");
            return false;
        }
        if (strtolower($area->getOwner()) !== strtolower($owner->getName())) {
            $owner->sendMessage($this->prefix . "자신의 섬으로만 회사를 세울 수 있습니다!");
            return false;
        }
        $money = EconomyAPI::getInstance()->myMoney($owner);
        if ($money < $require = $this->getOpenPrice()) {
            $owner->sendMessage($this->prefix . "돈이 부족합니다! (필요한 돈: §a{$require}§7원)");
            return false;
        }
        if ($this->hasCompany($owner)) {
            $owner->sendMessage($this->prefix . "당신은 이미 다른 회사소속 입니다!");
            return false;
        }
        EconomyAPI::getInstance()->reduceMoney($owner, $require);
        $this->db["players"][strtolower($owner->getName())]["companyName"] = $companyName;
        $this->db["players"][strtolower($owner->getName())]["companyId"] = $companyId;
        $this->db["point"]["{$companyId}번"] = 0;
        $this->db["company"]["{$companyId}번"] = [];
        $this->db["company"]["{$companyId}번"]["name"] = $companyName;
        $this->db["company"]["{$companyId}번"]["island"] = $companyIsland;
        $this->db["company"]["{$companyId}번"]["owner"] = strtolower($owner->getName());
        $this->db["company"]["{$companyId}번"]["members"] = [];
        $this->db["company"]["{$companyId}번"]["members"][strtolower($owner->getName())] = "회장";
        $this->db["company"]["{$companyId}번"]["maxMember"] = 5;
        $this->db["company"]["{$companyId}번"]["queue"] = [];
        $this->data->setAll($this->db);
        $this->data->save();
        $this->getServer()->broadcastMessage($this->prefix . "{$owner->getName()} 님이 {$companyName} §r§7회사를 설립했습니다!");
        $owner->sendMessage($this->prefix . "{$companyName}§r§7 회사를 설립했습니다! [ 회사섬: §a{$companyIsland}§7, 회사 아이디: §a{$companyId}§7, 회장: §a{$owner->getName()}§7, 소요 비용: {$require}원 ]");
        return true;
    }

    public function ChangeOwner(Player $player, Player $nextOwner)
    {
        if ($this->hasCompany($player)) {
            if ($this->getRate($player) == "회장") {
                $id = $this->getCompanyId($player);
                $nid = $this->getCompanyId($nextOwner);
                if ($id == $nid) {
                    if ($this->getRate($nextOwner) == "부회장") {
                        $this->db["company"]["{$id}번"]["owner"] = strtolower($nextOwner->getName());
                        $this->db["company"]["{$id}번"]["members"][strtolower($player->getName())] = "사원";
                        $this->db["company"]["{$id}번"]["members"][strtolower($nextOwner->getName())] = "회장";
                        $player->sendMessage($this->prefix . "당신의 직급을 사원으로, {$nextOwner->getName()} 님의 직급을 회장으로 설정했습니다.");
                    } else {
                        $player->sendMessage($this->prefix . "{$nextOwner->getName()} 부회장에게만 양도할 수 있습니다.");
                    }
                } else {
                    $player->sendMessage($this->prefix . "{$nextOwner->getName()} 님은 당신 회사의 일원이 아닙니다.");
                }
            } else {
                $player->sendMessage($this->prefix . "당신은 회장이 아닙니다.");
            }
        } else {
            $player->sendMessage($this->prefix . "당신이 가입된 회사가 없습니다.");
        }
    }

    public function getCompanyId($player): int
    {
        if ($player instanceof Player)
            $player = $player->getName();
        $name = strtolower($player);
        if (! $this->isRegistered($player)) {
            return - 1;
        } else {
            if (! $this->hasCompany($player)) {
                return - 1;
            } else {
                return $this->db["players"][$name]["companyId"];
            }
        }
    }

    public function getMaxMemberById($companyId)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            return $this->db["company"]["{$companyId}번"]["maxMember"];
        } else {
            return false;
        }
    }

    public function getMaxMemberByName($companyName)
    {
        $companyId = $this->getCompanyIdByName($companyName);
        if (! ($companyId))
            return false;
        if (isset($this->db["company"]["{$companyId}번"])) {
            return $this->db["company"]["{$companyId}번"]["maxMember"];
        } else {
            return false;
        }
    }

    public function getMembersById($companyId)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            return $this->db["company"]["{$companyId}번"]["members"];
        } else {
            return [];
        }
    }

    public function getMembersByName($companyName)
    {
        $companyId = $this->getCompanyIdByName($companyName);
        if (! ($companyId))
            return false;
        if (isset($this->db["company"]["{$companyId}번"])) {
            return $this->db["company"]["{$companyId}번"]["members"];
        } else {
            return false;
        }
    }

    public function getCompanyNameById($companyId)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            return $this->db["company"]["{$companyId}번"]["name"];
        }
    }

    // $this->db["company"] ["{$companyId}번"] [string "name", string "owner", array "members", int "island", int "maxMember"]
    public function getOwnerById($companyId)
    {
        if (isset($this->db["company"]["{$companyId}번"])) {
            return $this->db["company"]["{$companyId}번"]["owner"];
        }
    }

    public function isQueue($player)
    {
        if ($player instanceof Player)
            $player = $player->getName();
        $name = strtolower($player);
        return is_numeric($this->db["players"][$name]["queue"]) ? true : false;
    }

    public function queueCompanyId($player)
    {
        if ($player instanceof Player)
            $player = $player->getName();
        $name = strtolower($player);
        if ($this->isQueue($player)) {
            return $this->db["players"][$name]["queue"];
        } else {
            return false;
        }
    }

    public function getCompanyIdByName($companyName)
    {
        $ids = [];
        foreach ($this->db["company"] as $id => $array) {
            if ($this->db["company"]["{$id}번"]["name"] == $companyName) {
                $ids[] = $this->db["company"]["{$id}번"]["name"];
            }
        }
        if (count($ids) < 1) {
            return false;
        } else {
            return $ids[0];
        }
    }

    public function getRate($player)
    {
        if ($player instanceof Player)
            $player = $player->getName();
        $name = strtolower($player);
        if ($this->hasCompany($player)) {
            $id = $this->getCompanyId($player);
            if (isset($this->db["company"]["{$id}번"])) {

                return $this->db["company"]["{$id}번"]["members"][$name];
            }
        } else {

            return false;
        }
    }

    public function isRegistered($player): bool
    {
        if ($player instanceof Player) {
            $player = $player->getName();
        }
        $name = strtolower($player);
        return isset($this->db["players"][$name]) ? true : false;
    }

    public function getOpenPrice()
    {
        return $this->c["openPrice"];
    }

    public function writeData($player)
    {
        if ($player instanceof Player)
            $player = $player->getName();
        $name = strtolower($player);
        $this->db["players"][$name] = [];
        $this->db["players"][$name]["companyId"] = false;
        $this->db["players"][$name]["companyName"] = false;
        $this->db["players"][$name]["queue"] = false;
    }

    public $chat = [];

    public function onJoin(PlayerJoinEvent $event)
    {
        $player = $event->getPlayer();
        if (! $this->isRegistered($player)) {
            $this->writeData($player);
            $this->getLogger()->info("Data 작성");
            return true;
        }
        if ($this->hasCompany($player)) {
            $id = $this->getCompanyId($player);
            $this->getLogger()->info("$id");
            $members = $this->getMembersById($id);
            $rate = $this->getRate($player);
            $count = 0;
            foreach ($members as $name => $res) {
                if ($this->getServer()->getPlayer($name) !== null) {
                    $count ++;
                    $this->getServer()
                        ->getPlayer($name)
                        ->sendMessage($this->prefix . "{$rate} §a{$player->getName()} 님§7이 접속했습니다!");
                }
            }
            $queueCount = count($this->db["company"]["{$id}번"]["queue"]);
            if ($rate == "회장") {
                $player->sendMessage($this->prefix . "현재 접속중인 사원은 §a{$count}§7명 입니다.");
                $player->sendMessage($this->prefix . "현재 가입신청 {$queueCount}명 대기중입니다.");
            }
        }
        $company = $this->getCompanyId($player);
        if ($company == - 1) {
            $cname = "회사없음";
        } else {
            $cname = $this->getCompanyNameById($company);
        }
    }

    public function open()
    { // $this->db["players"] [$name] = $companyId or false;
        $this->data = new Config($this->getDataFolder() . "Data.yml", Config::YAML, [
            "point" => [],
            "company" => [],
            "players" => []
        ]);
        $this->db = $this->data->getAll();
        $this->config = new Config($this->getDataFolder() . "Conf.yml", Config::YAML, [
            "openPrice" => 500000,
            "pluginPrefix" => "§l§6[§fCompany§6] §r§7"
        ]);
        $this->c = $this->config->getAll();
    }

    public function save($async = false)
    {
        $this->data->setAll($this->db);
        $this->data->save($async);
        $this->config->setAll($this->c);
        $this->config->save($async);
    }

    public function onDisable()
    {
        $this->save(true);
    }
}
