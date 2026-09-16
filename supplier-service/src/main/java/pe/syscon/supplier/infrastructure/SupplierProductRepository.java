package pe.syscon.supplier.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.supplier.domain.SupplierProduct;
public interface SupplierProductRepository extends JpaRepository<SupplierProduct,UUID> { Optional<SupplierProduct> findBySupplierIdAndSku(UUID supplierId,String sku); }
