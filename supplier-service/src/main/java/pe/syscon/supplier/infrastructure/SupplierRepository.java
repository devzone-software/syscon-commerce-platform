package pe.syscon.supplier.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.supplier.domain.Supplier;
public interface SupplierRepository extends JpaRepository<Supplier,UUID> {  }
