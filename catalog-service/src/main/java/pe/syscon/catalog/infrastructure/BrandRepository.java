package pe.syscon.catalog.infrastructure;
import java.util.*;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.catalog.domain.Brand;
public interface BrandRepository extends JpaRepository<Brand,UUID> {Optional<Brand> findByNameIgnoreCase(String name);}
