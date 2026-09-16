package pe.syscon.auth.infrastructure;
import org.springframework.data.jpa.repository.JpaRepository;
import pe.syscon.auth.domain.Permission;
public interface PermissionRepository extends JpaRepository<Permission,String> {}
