package pe.syscon.catalog.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.catalog.domain.Inventory;
public interface InventoryRepository extends JpaRepository<Inventory,UUID> {}
