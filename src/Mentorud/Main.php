<?php
namespace Mentorud;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\VanillaItems;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\Config;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\scheduler\Task;

class Main extends PluginBase implements Listener {
    /** @var array */
    protected array $playerItems = [];
    
    /** @var array */
    protected array $pendingRestores = [];
    
    /** @var Config */
    private Config $playerData;

    public function onEnable(): void {

        if (!is_dir($this->getDataFolder())) {
            mkdir($this->getDataFolder());
        }
        

        $this->playerData = new Config($this->getDataFolder() . "playerdata.yml", Config::YAML);
        

        $this->loadSavedData();
        

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        

        $this->getLogger()->info(TF::GREEN . "Mentorud plugin aktiválva! Szerző: rolandhrvth");
    }
    
    public function onDisable(): void {
        // Elmentjük a függőben lévő adatokat a plugin leállása előtt
        $this->savePendingData();
        $this->getLogger()->info(TF::RED . "Mentorud plugin leállítva!");
    }
    
    /**
     * Függőben lévő adatok betöltése
     */
    private function loadSavedData(): void {
        if ($this->playerData->exists("pending_restores")) {
            $this->pendingRestores = $this->playerData->get("pending_restores", []);
        }
    }
    
    /**
     * Függőben lévő adatok mentése
     */
    private function savePendingData(): void {
        // Csak a legszükségesebb adatokat mentjük perzisztensen
        $this->playerData->set("pending_restores", $this->pendingRestores);
        $this->playerData->save();
    }
    
    /**
     * Tárgyak adatainak sorosítása
     * 
     * @param array $items
     * @return array
     */
    private function serializeItems(array $items): array {
        $serialized = [];
        foreach ($items as $slot => $item) {
            $serialized[$slot] = $item->jsonSerialize();
        }
        return $serialized;
    }
    
    /**
     * Sorosított tárgyak visszaállítása
     * 
     * @param array $serializedItems
     * @return array
     */
private function deserializeItems(array $serializedItems): array {
    $items = [];
    foreach ($serializedItems as $slot => $data) {
        try {
            // JSON adat dekódolása és tárgy létrehozása
            $itemData = json_decode($data, true);
            $items[$slot] = ItemFactory::getInstance()->get(
                $itemData['id'], 
                $itemData['meta'] ?? 0, 
                $itemData['count'] ?? 1
            );
        } catch (\Throwable $e) {
            // Hiba esetén alapértelmezett érték vagy logolás
            $this->getLogger()->error("Hiba történt a tárgy deszerializációja közben: " . $e->getMessage());
            $items[$slot] = null; // Hiba esetén alapértelmezett érték
        }
    }
    return $items;
}
    
    /**
     * A parancs kezelője
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (strtolower($command->getName()) === "mentorud") {
            $this->getLogger()->info("A /mentorud parancsot hívta: " . $sender->getName());
            
            if ($sender instanceof Player) {

                $this->giveMentorud($sender);
                return true;
            } else {
                $sender->sendMessage(TF::RED . "Ez a parancs csak játékosok számára érhető el. Kérlek, próbáld meg a játékban!");
                return false;
            }
        }
        return false;
    }
    
    /**
     * MentőRúd tárgy kiadása a játékosnak
     * 
     * @param Player $player
     */
    private function giveMentorud(Player $player): void {

        $mentorud = VanillaItems::BLAZE_ROD();
        
        $mentorud->setCustomName(TF::GOLD . "§r§l§6MentőRúd");
        $mentorud->setLore([
            TF::YELLOW . "Ez a rúd megőrzi a tárgyaidat halál esetén.",
            TF::GRAY . "Automatikusan aktiválódik halálkor."
        ]);
        
        $mentorud->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1));
        
        $namedtag = $mentorud->getNamedTag();
        $namedtag->setByte("isMentorud", 1);
        $mentorud->setNamedTag($namedtag);
        
        $inventory = $player->getInventory();
        
        if ($inventory->canAddItem($mentorud)) {
            $inventory->addItem($mentorud);
            $player->sendMessage(TF::GREEN . "Megkaptad a Mentőrudat! " . TF::YELLOW . "Ez a rúd megőrzi a cuccaidat halál esetén.");
            $this->getLogger()->info($player->getName() . " megkapta a Mentőrudat.");
        } else {
            $player->sendMessage(TF::RED . "Nem tudtuk hozzáadni a Mentőrudat az inventorydhoz. Kérlek, ellenőrizd, hogy van-e elég hely.");
            $this->getLogger()->warning("Nem tudtuk hozzáadni a Mentőrudat " . $player->getName() . " inventoryjához.");
        }
    }
    
    /**
     * A játékos csatlakozásának figyelése - függő visszaállítások ellenőrzése
     * 
     * @param PlayerJoinEvent $event
     * @priority LOWEST
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if (isset($this->pendingRestores[$playerName])) {
            $this->getScheduler()->scheduleDelayedTask(new RestoreItemsTask($this, $player, $this->pendingRestores[$playerName]), 20);
            
            unset($this->pendingRestores[$playerName]);
            $this->savePendingData();
        }
    }
    
    /**
     * Játékos kilépésének kezelése
     * 
     * @param PlayerQuitEvent $event
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if (isset($this->playerItems[$playerName])) {

            $this->pendingRestores[$playerName] = $this->serializeItems($this->playerItems[$playerName]);
            unset($this->playerItems[$playerName]);
            $this->savePendingData();
        }
    }
    
    /**
     * Mentőrúd ellenőrzése
     * 
     * @param Player $player
     * @return int
     */
    private function countMentoruds(Player $player): int {
        $inventory = $player->getInventory();
        $contents = $inventory->getContents();
        $mentorudCount = 0;
        
        foreach ($contents as $item) {
            if ($item->getNamedTag()->getByte("isMentorud", 0) === 1) {
                $mentorudCount += $item->getCount();
            }
        }
        
        return $mentorudCount;
    }
    
    /**
     * Játékos halálának kezelése
     * 
     * @param PlayerDeathEvent $event
     * @priority HIGHEST
     */
    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $inventory = $player->getInventory();
        $contents = $inventory->getContents();
        $armorContents = $player->getArmorInventory()->getContents();
        $enderContents = $player->getEnderInventory()->getContents();
        $offHandItem = $player->getOffHandInventory()->getContents();
        
        $mentorudCount = $this->countMentoruds($player);
        
        if ($mentorudCount > 0) {
            $this->playerItems[$playerName] = [];
            $this->playerItems[$playerName]['inventory'] = $contents;
            $this->playerItems[$playerName]['armor'] = $armorContents;
            $this->playerItems[$playerName]['enderchest'] = $enderContents;
            $this->playerItems[$playerName]['offhand'] = $offHandItem;
            $this->playerItems[$playerName]['mentorud_count'] = $mentorudCount;
            
            $event->setDrops([]);
            
            $player->sendMessage(TF::GREEN . "Megőrizted a cuccaidat a Mentőrúd segítségével! " . TF::YELLOW . "Egy Mentőrudat felhasználtál.");
            $this->getLogger()->info($playerName . " meghalt, de a Mentőrúd megmentette a tárgyait.");
        }
    }
    
    /**
     * Játékos újraéledésének kezelése
     * 
     * @param PlayerRespawnEvent $event
     * @priority LOWEST
     */
    public function onPlayerRespawn(PlayerRespawnEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if (isset($this->playerItems[$playerName])) {
            $this->getScheduler()->scheduleDelayedTask(new RespawnRestoreTask($this, $player, $this->playerItems[$playerName]), 10);
            
        }
    }
    
    /**
     * Eltávolítja a játékos mentett adatait
     * 
     * @param string $playerName
     */
    public function removePlayerItems(string $playerName): void {
        if (isset($this->playerItems[$playerName])) {
            unset($this->playerItems[$playerName]);
        }
    }
    
    /**
     * Tárgyak visszaállítása a játékosnak
     * 
     * @param Player $player
     * @param array $serializedItems
     */
    public function restorePlayerItems(Player $player, array $serializedItems): void {
        $playerName = $player->getName();
        
        try {
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getEnderInventory()->clearAll();
            $player->getOffHandInventory()->clearAll();
            
            $inventory = $this->deserializeItems($serializedItems['inventory'] ?? []);
            $armor = $this->deserializeItems($serializedItems['armor'] ?? []);
            $enderchest = $this->deserializeItems($serializedItems['enderchest'] ?? []);
            $offhand = $this->deserializeItems($serializedItems['offhand'] ?? []);
            $mentorudCount = $serializedItems['mentorud_count'] ?? 1;
            
            foreach ($inventory as $slot => $item) {
                if (!($item->getNamedTag() instanceof CompoundTag && $item->getNamedTag()->getByte("isMentorud", 0) === 1)) {
                    $player->getInventory()->setItem($slot, $item);
                }
            }
            
            foreach ($armor as $slot => $item) {
                $player->getArmorInventory()->setItem($slot, $item);
            }
            
            foreach ($enderchest as $slot => $item) {
                $player->getEnderInventory()->setItem($slot, $item);
            }
            
            foreach ($offhand as $slot => $item) {
                $player->getOffHandInventory()->setItem($slot, $item);
            }
            
            if ($mentorudCount > 1) {
                $mentorud = VanillaItems::BLAZE_ROD();
                $mentorud->setCustomName(TF::GOLD . "§r§l§6MentőRúd");
                $mentorud->setLore([
                    TF::YELLOW . "Ez a rúd megőrzi a tárgyaidat halál esetén.",
                    TF::GRAY . "Automatikusan aktiválódik halálkor."
                ]);
                $mentorud->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1));
                
                $namedtag = $mentorud->getNamedTag();
                $namedtag->setByte("isMentorud", 1);
                $mentorud->setNamedTag($namedtag);
                
                $mentorud->setCount($mentorudCount - 1);
                $player->getInventory()->addItem($mentorud);
                
                $player->sendMessage(TF::GREEN . "Visszakaptad a mentett tárgyaidat! " . TF::YELLOW . "Most " . ($mentorudCount - 1) . " Mentőrudad van.");
            } else {
                $player->sendMessage(TF::GREEN . "Visszakaptad a mentett tárgyaidat! " . TF::RED . "De nincs több Mentőrudad!");
            }
            
            $this->getLogger()->info($playerName . " visszakapta a mentett tárgyait.");
        } catch (\Throwable $e) {
            $this->getLogger()->error("Hiba történt a tárgyak visszaállítása közben " . $playerName . " játékosnak: " . $e->getMessage());
            $player->sendMessage(TF::RED . "Hiba történt a tárgyak visszaállítása közben. Kérlek, jelentsd a szerver adminisztrátorának!");
        }
    }
}

/**
 * Feladata, hogy visszaállítsa a tárgyakat újraéledéskor
 */
class RespawnRestoreTask extends Task {
    /** @var Main */
    private $plugin;
    /** @var Player */
    private $player;
    /** @var array */
    private $savedData;
    
    public function __construct(Main $plugin, Player $player, array $savedData) {
        $this->plugin = $plugin;
        $this->player = $player;
        $this->savedData = $savedData;
    }
    
    public function onRun(): void {
        if ($this->player->isOnline()) {
            $inventory = $this->player->getInventory();
            $armorInventory = $this->player->getArmorInventory();
            $enderInventory = $this->player->getEnderInventory();
            $offHandInventory = $this->player->getOffHandInventory();
            
            $mentorudCount = $this->savedData['mentorud_count'] ?? 1;
            
            $inventory->clearAll();
            $armorInventory->clearAll();
            $enderInventory->clearAll();
            $offHandInventory->clearAll();
            
            if (isset($this->savedData['inventory'])) {
                foreach ($this->savedData['inventory'] as $slot => $item) {
                    if ($item->getNamedTag()->getByte("isMentorud", 0) !== 1) {
                        $inventory->setItem($slot, $item);
                    }
                }
            }
            
            if (isset($this->savedData['armor'])) {
                foreach ($this->savedData['armor'] as $slot => $item) {
                    $armorInventory->setItem($slot, $item);
                }
            }
            
            if (isset($this->savedData['enderchest'])) {
                foreach ($this->savedData['enderchest'] as $slot => $item) {
                    $enderInventory->setItem($slot, $item);
                }
            }
            
            if (isset($this->savedData['offhand'])) {
                foreach ($this->savedData['offhand'] as $slot => $item) {
                    $offHandInventory->setItem($slot, $item);
                }
            }
            
            if ($mentorudCount > 1) {
                $mentorud = VanillaItems::BLAZE_ROD();
                $mentorud->setCustomName(TF::GOLD . "§r§l§6MentőRúd");
                $mentorud->setLore([
                    TF::YELLOW . "Ez a rúd megőrzi a tárgyaidat halál esetén.",
                    TF::GRAY . "Automatikusan aktiválódik halálkor."
                ]);
                $mentorud->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 1));
                
                $namedtag = $mentorud->getNamedTag();
                $namedtag->setByte("isMentorud", 1);
                $mentorud->setNamedTag($namedtag);
                
                $mentorud->setCount($mentorudCount - 1);
                $inventory->addItem($mentorud);
                
                $this->player->sendMessage(TF::GREEN . "Visszakaptad a tárgyaidat! " . TF::YELLOW . "Most " . ($mentorudCount - 1) . " Mentőrudad van.");
            } else {
                $this->player->sendMessage(TF::GREEN . "Visszakaptad a tárgyaidat! " . TF::RED . "De nincs több Mentőrudad!");
            }
            
            $this->plugin->removePlayerItems($this->player->getName());
        }
    }
}

/**
 * Feladata, hogy visszaállítsa a tárgyakat csatlakozáskor
 */
class RestoreItemsTask extends Task {
    /** @var Main */
    private $plugin;
    /** @var Player */
    private $player;
    /** @var array */
    private $items;
    
    public function __construct(Main $plugin, Player $player, array $items) {
        $this->plugin = $plugin;
        $this->player = $player;
        $this->items = $items;
    }
    
    public function onRun(): void {
        if ($this->player->isOnline()) {
            $this->plugin->restorePlayerItems($this->player, $this->items);
        }
    }
}
