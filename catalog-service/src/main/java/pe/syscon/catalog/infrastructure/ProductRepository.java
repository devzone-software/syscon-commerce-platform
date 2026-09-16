package pe.syscon.catalog.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.catalog.domain.Product;
public interface ProductRepository extends JpaRepository<Product,UUID> {Optional<Product> findBySku(String sku);}
